app.component('govbr-satisfaction-requests', {
    template: $TEMPLATES['govbr-satisfaction-requests'],

    setup() {
        // os textos estão localizados no arquivo texts.php deste componente
        const text = Utils.getTexts('govbr-satisfaction-requests');
        const messages = useMessages();
        return { text, messages };
    },

    data() {
        return {
            registros: [],
            total: 0,
            pagina: 1,
            paginas: 0,
            totais: {},
            carregando: false,

            // configuração vigente, para os avisos do topo
            status: { devMode: false, faltando: [], servicos: [] },

            filtros: { situacao: '', servico: '' },

            // conteúdo da solicitação aberta no momento
            payload: null,
            payloadMotivo: null,
            // se o conteúdo exibido é cópia do envio ou reconstrução do cadastro
            payloadReconstruido: false,
            // qual bloco acabou de ser copiado, para a confirmação na tela
            copiado: null,
            carregandoPayload: false,

            // Cada pedido recebe um número, e só a resposta do mais recente é
            // aceita: sem isso, uma consulta lenta sem filtro que voltasse
            // depois de outra filtrada sobrescreveria a lista já filtrada.
            geracao: 0,
            geracaoPayload: 0,
        };
    },

    computed: {
        situacoes() {
            return ['pendente', 'enviado', 'recusado', 'sem-cpf'];
        },

        // Os nomes de campo são os da API de propósito: a tela serve para
        // conferir o que o BSC recebeu, e traduzi-los tornaria a conferência
        // contra a documentação deles mais difícil, não mais fácil.
        filtroAtivo() {
            return Boolean(this.filtros.situacao || this.filtros.servico);
        },

        payloadCampos() {
            if (!this.payload) {
                return [];
            }

            const mascarados = ['cpfCidadao', 'email', 'nomeCidadao'];

            return Object.entries(this.payload).map(([chave, valor]) => ({
                chave,
                valor: typeof valor === 'boolean' ? String(valor) : valor,
                mascarado: mascarados.includes(chave),
            }));
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
                // o aviso de configuração é acessório: a lista abaixo continua
                // utilizável sem ele, então uma falha aqui não vira mensagem
            }
        },

        // `acumular` anexa a página nova ao que já está na tela, em vez de
        // trocar: a lista é varrida de cima a baixo à procura de um registro, e
        // trocar o conteúdo faria perder o lugar a cada avanço.
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
                    // devolve a página avançada pelo clique: sem isto, o próximo
                    // "Carregar Mais" depois de um erro pularia um trecho da lista
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
            this.carregandoPayload = true;

            // mesma guarda do carregar(): abrir dois conteúdos em sequência
            // rápida não pode deixar a resposta antiga sobrescrever a nova
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

        // A tabela mostra só o começo do motivo: a recusa do BSC vem com a
        // pilha inteira do Java, e despejar isso na célula empurra a linha
        // para cinco alturas. O texto completo fica no modal, e o title
        // atende quem passar o mouse.
        resumo(detalhe) {
            if (!detalhe) {
                return '';
            }

            // corta na primeira frase, que é onde mora o que interessa
            const frase = detalhe.split(/[.;]\s/)[0];

            return frase.length > 70 ? frase.slice(0, 70) + '…' : frase;
        },

        // Copiar é o caminho normal daqui: o conteúdo vai para um chamado, um
        // e-mail ao BSC ou o Postman, e selecionar JSON num bloco rolável é
        // trabalhoso.
        //
        // A área de transferência moderna exige contexto seguro (HTTPS ou
        // localhost); o textarea temporário cobre instalações em HTTP.
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

        // O payload no mesmo formato da resposta: os dois são JSON de API, e
        // alternar entre lista de campos e bloco de código obrigaria a mudar de
        // leitura no meio do modal.
        formatarJson(valor) {
            return JSON.stringify(valor, null, 2);
        },

        // O corpo já chega limpo do HttpClient. O filtro aqui é para as linhas
        // gravadas antes dessa limpeza existir, que ainda carregam a pilha de
        // exceção do Java — sem ele, o histórico continuaria ilegível.
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

        // as situações compartilham os tons do mc-status: verde para o que
        // seguiu, amarelo para o que aguarda, vermelho para o que não sairá
        tom(situacao) {
            if (situacao === 'enviado') return 'success';
            if (situacao === 'recusado') return 'danger';
            if (situacao === 'pendente') return 'warning';
            return 'danger';
        },

        // mesma formatação do Security: McDate respeita o idioma configurado
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
