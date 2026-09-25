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
            status: { devMode: false, faltando: [], servicos: [] },

            filtros: { situacao: '', servico: '' },

            // solicitação aberta no modal
            payload: null,
            payloadMotivo: null,
            payloadReconstruido: false,
            resposta: null,
            copiado: null,
            carregandoPayload: false,

            // id da solicitação sendo devolvida à fila
            devolvendo: null,

            // só a resposta do pedido mais recente é aceita
            geracao: 0,
            geracaoPayload: 0,
        };
    },

    computed: {
        filtroAtivo() {
            return Boolean(this.filtros.situacao || this.filtros.servico);
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

        async verPayload(id, abrir) {
            abrir();

            this.payload = null;
            this.payloadMotivo = null;
            this.payloadReconstruido = false;
            this.resposta = null;
            this.carregandoPayload = true;

            const geracao = ++this.geracaoPayload;

            try {
                const response = await fetch(Utils.createUrl('govbr-satisfaction-requests', 'payload', { id }));
                const data = await response.json();

                if (geracao !== this.geracaoPayload) {
                    return;
                }

                if (!response.ok) {
                    this.payloadMotivo = data.error || this.text('payloadErro');
                    return;
                }

                this.payload = data.payload;
                this.payloadMotivo = data.motivo;
                this.payloadReconstruido = !!data.reconstruido;
                this.resposta = data.resposta;
            } catch (error) {
                if (geracao === this.geracaoPayload) {
                    this.payloadMotivo = this.text('payloadErro');
                }
            } finally {
                if (geracao === this.geracaoPayload) {
                    this.carregandoPayload = false;
                }
            }
        },

        podeDevolver(registro) {
            return ['recusado', 'sem-cpf'].includes(registro.situacao);
        },

        // atualiza a linha e os totais no lugar, sem recarregar a lista acumulada
        async devolverAFila(registro, modal) {
            this.devolvendo = registro.id;

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

                modal.close();
                this.messages.success(this.text('devolvido'));
            } catch (error) {
                this.messages.error(this.text('devolverErro'));
            } finally {
                this.devolvendo = null;
            }
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

        // primeira frase do motivo; o texto completo fica no modal e no title
        resumo(detalhe) {
            if (!detalhe) {
                return '';
            }

            const frase = detalhe.split(/[.;]\s/)[0];

            return frase.length > 70 ? frase.slice(0, 70) + '…' : frase;
        },

        async copiar(texto, chave) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(texto);
                } else {
                    const campo = document.createElement('textarea');
                    campo.value = texto;
                    campo.style.position = 'fixed';
                    campo.style.opacity = '0';
                    document.body.appendChild(campo);
                    campo.select();
                    document.execCommand('copy');
                    document.body.removeChild(campo);
                }

                this.copiado = chave;
                setTimeout(() => {
                    if (this.copiado === chave) {
                        this.copiado = null;
                    }
                }, 2000);
            } catch (error) {
                this.messages.error(this.text('copiarErro'));
            }
        },

        formatarJson(valor) {
            return JSON.stringify(valor, null, 2);
        },

        // corpo só quando difere do resumo
        corpoAcrescenta(registro) {
            if (!this.resposta) {
                return false;
            }

            return this.formatarResposta(this.resposta).trim() !== (registro.detalhe || '').trim();
        },

        formatarResposta(corpo) {
            const ruido = ['stackTrace', 'suppressed', 'cause', 'localizedMessage', 'instance', 'type'];

            try {
                const json = JSON.parse(corpo);

                if (json && typeof json === 'object' && !Array.isArray(json)) {
                    ruido.forEach((chave) => delete json[chave]);
                }

                return JSON.stringify(json, null, 2);
            } catch (e) {
                // o proxy responde texto puro ("no healthy upstream")
                return corpo;
            }
        },

        tom(situacao) {
            return { enviado: 'success', pendente: 'warning' }[situacao] ?? 'danger';
        },

        quando(timestamp) {
            if (!timestamp) {
                return '—';
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
