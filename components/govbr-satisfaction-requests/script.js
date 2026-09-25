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
            status: { devMode: false, faltando: [], servicos: [], loteIntervalo: 10, loteMaximo: 500 },

            filtros: { situacao: '', servico: '' },

            // linhas com o histórico aberto, e versão para recarregá-lo
            abertos: {},
            versoes: {},

            // id da solicitação sendo devolvida à fila
            devolvendo: null,
            devolvendoTodas: false,

            // só a resposta do pedido mais recente é aceita
            geracao: 0,
        };
    },

    computed: {
        filtroAtivo() {
            return Boolean(this.filtros.situacao || this.filtros.servico);
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

        // anexa a página
        async carregar(acumular = false) {
            this.carregando = true;
            const geracao = ++this.geracao;

            try {
                const url = Utils.createUrl('govbr-satisfaction-requests', 'index', {
                    pagina: this.pagina,
                    situacao: this.filtros.situacao,
                    servico: this.filtros.servico,
                });

                const response = await fetch(url);
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

        // clicar no contador filtra por aquela situação; clicar de novo limpa
        alternarSituacao(situacao) {
            this.filtros.situacao = this.filtros.situacao === situacao ? '' : situacao;
            this.filtrar();
        },

        limparFiltros() {
            this.filtros.situacao = '';
            this.filtros.servico = '';
            this.filtrar();
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
