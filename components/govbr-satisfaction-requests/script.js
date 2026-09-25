// intervalo das leituras no monitoramento em tempo real
const GOVBR_SATISFACTION_MONITOR_INTERVAL = 5000;

app.component('govbr-satisfaction-requests', {
    template: $TEMPLATES['govbr-satisfaction-requests'],

    setup() {
        // os textos estão localizados no arquivo texts.php deste componente
        const text = Utils.getTexts('govbr-satisfaction-requests');
        const messages = useMessages();

        // substitui cada `%s` do texto pelo próximo argumento
        const fmt = (chave, ...valores) => valores.reduce((s, v) => s.replace('%s', v), text(chave));

        return { text, fmt, messages };
    },

    data() {
        return {
            situacoes: ['pendente', 'enviado', 'recusado', 'sem-cpf'],

            registros: [],
            total: 0,
            pagina: 1,
            paginas: 0,
            totais: {},
            carregando: false,

            // configuração vigente, para os avisos do topo
            status: { devMode: false, faltando: [], servicos: [], loteIntervalo: 10, loteMaximo: 500, revelacao: { disponivel: false, autorizado: false, motivoMinimo: 10, segundos: 120 } },

            filtros: { situacao: '', servico: '', busca: '' },

            // ids selecionados, entre páginas e filtros
            selecionados: {},
            devolvendoSelecionadas: false,

            // linhas com o histórico aberto, e versão para recarregá-lo
            abertos: {},
            versoes: {},

            // id da solicitação sendo devolvida à fila
            devolvendo: null,
            devolvendoTodas: false,

            // só a resposta do pedido mais recente é aceita
            geracao: 0,

            // monitoramento em tempo real
            tempoReal: false,
            proximaLeitura: null,
            atualizadoEm: null,
            falhouAoAtualizar: false,
            tick: 0,
        };
    },

    computed: {
        filtroAtivo() {
            return Boolean(this.filtros.situacao || this.filtros.servico || this.filtros.busca.trim());
        },

        totalGeral() {
            return Object.values(this.totais).reduce((soma, n) => soma + n, 0);
        },

        // da página carregada, as que podem voltar à fila
        selecionaveis() {
            return this.registros.filter(registro => this.selecionavel(registro));
        },

        paginaToda() {
            return this.selecionaveis.length > 0 && this.selecionaveis.every(registro => this.selecionados[registro.id]);
        },

        totalSelecionadas() {
            return Object.keys(this.selecionados).length;
        },

        acimaDoTeto() {
            return this.totalSelecionadas > this.status.loteMaximo;
        },

        podeDevolverSelecionadas() {
            return this.totalSelecionadas > 0 && !this.acimaDoTeto && !this.devolvendoSelecionadas;
        },

        podeDevolverTodas() {
            return this.filtros.situacao === 'recusado' && this.total > 0;
        },

        // quantas saem neste clique: o total do filtro, até o teto
        loteTamanho() {
            return Math.min(this.total, this.status.loteMaximo);
        },
    },

    mounted() {
        this.carregarStatus();
        this.carregar();
    },

    beforeUnmount() {
        this.tempoReal = false;
        this.cancelarLeitura();
    },

    methods: {
        async carregarStatus() {
            try {
                const response = await fetch(Utils.createUrl('govbr-satisfaction-requests', 'status'));

                if (response.ok) {
                    this.status = await response.json();
                }
            } catch (error) {
            }
        },

        urlDaPagina(pagina) {
            return Utils.createUrl('govbr-satisfaction-requests', 'index', {
                pagina,
                situacao: this.filtros.situacao,
                servico: this.filtros.servico,
                busca: this.filtros.busca.trim(),
            });
        },

        // anexa a página
        async carregar(acumular = false) {
            this.carregando = true;
            const geracao = ++this.geracao;

            try {
                const response = await fetch(this.urlDaPagina(this.pagina));
                const data = await response.json();

                if (geracao !== this.geracao) {
                    return;
                }

                if (!response.ok) {
                    this.messages.error(data.error || this.text('erroAoCarregar'));
                    return;
                }

                this.registros = acumular ? this.registros.concat(data.registros) : data.registros;
                this.total = data.total;
                this.pagina = data.pagina;
                this.paginas = data.paginas;
                this.totais = data.totais;
            } catch (error) {
                if (geracao === this.geracao) {
                    // devolve a página avançada pelo clique
                    if (acumular) {
                        this.pagina -= 1;
                    }

                    this.messages.error(this.text('erroAoCarregar'));
                }
            } finally {
                if (geracao === this.geracao) {
                    this.carregando = false;
                }
            }
        },

        alternarTempoReal() {
            this.tempoReal = !this.tempoReal;

            if (this.tempoReal) {
                this.atualizar();
                this.agendarLeitura();
            } else {
                this.cancelarLeitura();
            }
        },

        // próxima leitura só depois que a atual termina
        agendarLeitura() {
            this.cancelarLeitura();

            this.proximaLeitura = setTimeout(async () => {
                if (!document.hidden && !this.carregando) {
                    await this.atualizar();
                }

                if (this.tempoReal) {
                    this.agendarLeitura();
                }
            }, GOVBR_SATISFACTION_MONITOR_INTERVAL);
        },

        cancelarLeitura() {
            if (this.proximaLeitura) {
                clearTimeout(this.proximaLeitura);
                this.proximaLeitura = null;
            }
        },

        // relê as páginas já carregadas, sem indicador de carregamento
        async atualizar() {
            if (this.carregando) {
                return;
            }

            const geracao = ++this.geracao;

            try {
                let registros = [];
                let data = null;

                for (let pagina = 1; pagina <= Math.max(1, this.pagina); pagina++) {
                    const response = await fetch(this.urlDaPagina(pagina));
                    data = await response.json();

                    if (!response.ok) {
                        throw new Error(data.error);
                    }

                    registros = registros.concat(data.registros);
                }

                if (geracao !== this.geracao) {
                    return;
                }

                this.registros = registros;
                this.total = data.total;
                this.paginas = data.paginas;
                this.totais = data.totais;
                this.atualizadoEm = Date.now();
                this.falhouAoAtualizar = false;
                this.tick += 1;
            } catch (error) {
                // um aviso por queda
                if (geracao === this.geracao && !this.falhouAoAtualizar) {
                    this.falhouAoAtualizar = true;
                    this.messages.error(error.message || this.text('erroAoCarregar'));
                }
            }
        },

        hora(timestamp) {
            return new McDate(new Date(timestamp)).format({ timeStyle: 'medium' });
        },

        alternarHistorico(registro) {
            this.abertos[registro.id] = !this.abertos[registro.id];
        },

        // recusada e sem CPF voltam à fila; pendente que já falhou antecipa a tentativa
        podeDevolver(registro) {
            return ['recusado', 'sem-cpf'].includes(registro.situacao) || this.aguardaRetentativa(registro);
        },

        aguardaRetentativa(registro) {
            return registro.situacao === 'pendente' && !!registro.detalhe;
        },

        // atualiza a linha e os totais no lugar, sem recarregar a lista acumulada
        async devolverAFila(registro, modal) {
            this.devolvendo = registro.id;
            const antecipou = this.aguardaRetentativa(registro);

            try {
                const response = await fetch(Utils.createUrl('govbr-satisfaction-requests', 'requeue'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: registro.id }),
                });

                const data = await response.json();

                if (!response.ok) {
                    this.messages.error(data.error || this.text('devolverErro'));
                    return;
                }

                this.totais[registro.situacao] = Math.max(0, (this.totais[registro.situacao] || 0) - 1);
                this.totais[data.situacao] = (this.totais[data.situacao] || 0) + 1;

                registro.situacao = data.situacao;
                registro.tentativas = data.tentativas;
                registro.disparada = data.disparada;
                this.versoes[registro.id] = (this.versoes[registro.id] || 0) + 1;

                modal.close();
                this.messages.success(this.text(antecipou ? 'tentarAgoraFeito' : 'devolvido'));
            } catch (error) {
                this.messages.error(this.text('devolverErro'));
            } finally {
                this.devolvendo = null;
            }
        },

        // todas as recusadas do filtro atual, escalonadas pelo servidor
        async devolverTodas(modal) {
            this.devolvendoTodas = true;

            try {
                const response = await fetch(Utils.createUrl('govbr-satisfaction-requests', 'requeueAll'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ servico: this.filtros.servico }),
                });

                const data = await response.json();

                if (!response.ok) {
                    this.messages.error(data.error || this.text('devolverTodasErro'));
                    return;
                }

                modal.close();

                const ultima = this.duracao(Math.max(0, data.devolvidas - 1) * data.intervalo);
                this.messages.success(this.fmt('devolverTodasFeito', data.devolvidas, ultima));

                if (data.restantes > 0) {
                    this.messages.alert(this.fmt('devolverTodasRestantes', data.restantes));
                }

                this.filtrar();
            } catch (error) {
                this.messages.error(this.text('devolverTodasErro'));
            } finally {
                this.devolvendoTodas = false;
            }
        },

        // "40 s", "17 min", "1 h 23 min"
        duracao(segundos) {
            if (segundos < 60) return `${segundos} s`;

            const min = Math.round(segundos / 60);
            if (min < 60) return `${min} min`;

            const h = Math.floor(min / 60);
            const resto = min % 60;
            return resto ? `${h} h ${resto} min` : `${h} h`;
        },

        escolherSituacao(situacao) {
            this.filtros.situacao = situacao;
            this.filtrar();
        },

        // espera a digitação parar
        buscar() {
            clearTimeout(this.esperaBusca);
            this.esperaBusca = setTimeout(() => this.filtrar(), 500);
        },

        limparFiltros() {
            clearTimeout(this.esperaBusca);
            this.filtros.situacao = '';
            this.filtros.servico = '';
            this.filtros.busca = '';
            this.filtrar();
        },

        selecionavel(registro) {
            return ['recusado', 'sem-cpf'].includes(registro.situacao);
        },

        alternarSelecao(registro) {
            const selecionados = { ...this.selecionados };

            if (selecionados[registro.id]) {
                delete selecionados[registro.id];
            } else {
                selecionados[registro.id] = true;
            }

            this.selecionados = selecionados;
        },

        // marca ou desmarca as selecionáveis da página
        alternarPagina() {
            const selecionados = { ...this.selecionados };
            const marcar = !this.paginaToda;

            this.selecionaveis.forEach(registro => {
                if (marcar) {
                    selecionados[registro.id] = true;
                } else {
                    delete selecionados[registro.id];
                }
            });

            this.selecionados = selecionados;
        },

        limparSelecao() {
            this.selecionados = {};
        },

        async devolverSelecionadas(modal) {
            this.devolvendoSelecionadas = true;

            try {
                const response = await fetch(Utils.createUrl('govbr-satisfaction-requests', 'requeueSelected'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: Object.keys(this.selecionados).map(Number) }),
                });

                const data = await response.json();

                if (!response.ok) {
                    this.messages.error(data.error || this.text('devolverSelecionadasErro'));
                    return;
                }

                modal.close();

                const ultima = this.duracao(Math.max(0, data.devolvidas - 1) * data.intervalo);
                this.messages.success(this.fmt('devolverTodasFeito', data.devolvidas, ultima));

                if (data.ignoradas > 0) {
                    this.messages.alert(this.fmt('devolverSelecionadasIgnoradas', data.ignoradas));
                }

                this.selecionados = {};
                this.filtrar();
            } catch (error) {
                this.messages.error(this.text('devolverSelecionadasErro'));
            } finally {
                this.devolvendoSelecionadas = false;
            }
        },

        filtrar() {
            this.pagina = 1;
            this.carregar();
        },

        carregarMais() {
            this.pagina += 1;
            this.carregar(true);
        },

        // primeira frase do motivo, até 70 caracteres
        resumo(detalhe) {
            if (!detalhe) {
                return '';
            }

            const frase = detalhe.split(/[.;]\s/)[0];

            return frase.length > 70 ? frase.slice(0, 70) + '…' : frase;
        },

        tom(situacao) {
            return { enviado: 'success', pendente: 'warning' }[situacao] ?? 'danger';
        },

        quando(timestamp) {
            if (!timestamp) {
                return '-';
            }

            const data = new McDate(new Date(timestamp * 1000));

            return `${data.date('numeric year')} ${data.time()}`;
        },

        rotuloServico(id) {
            const servico = this.status.servicos.find(s => s.id === String(id));
            return servico ? this.text(servico.chave) : id;
        },
    },
});
