// ícone de cada situação de envio e de tentativa
const GOVBR_DISPATCHES_ICONES = {
    pendente: 'govbr-satisfaction-pending',
    enviado: 'govbr-satisfaction-success',
    simulado: 'govbr-satisfaction-simulated',
    retentar: 'govbr-satisfaction-error',
    recusado: 'govbr-satisfaction-rejected',
    'sem-cpf': 'govbr-satisfaction-rejected',
    substituido: 'govbr-satisfaction-replaced',
};

app.component('govbr-satisfaction-dispatches', {
    template: $TEMPLATES['govbr-satisfaction-dispatches'],

    props: {
        requestId: {
            type: Number,
            required: true,
        },

        // muda a cada leitura do monitoramento em tempo real
        leitura: {
            type: Number,
            default: 0,
        },

        // do GET_status: cofre configurado e usuário na lista
        revelacao: {
            type: Object,
            default: () => ({ disponivel: false, autorizado: false, motivoMinimo: 10, segundos: 120 }),
        },
    },

    setup() {
        const text = Utils.getTexts('govbr-satisfaction-dispatches');
        const messages = useMessages();

        // substitui cada `%s` do texto pelo próximo argumento
        const formatar = (chave, ...valores) => valores.reduce((s, v) => s.replace('%s', v), text(chave));

        return { text, formatar, messages };
    },

    data() {
        return {
            envios: [],
            pagina: 1,
            paginas: 0,
            carregando: false,
            carregandoMais: false,
            erro: null,
            previa: { payload: null, motivo: null },

            // payload real por id da tentativa, só enquanto a janela está aberta
            revelados: {},
            janelaAte: 0,
            restantes: 0,
            agora: Date.now(),
            pendente: null,
            motivo: '',
            liberando: false,
            revelando: null,
        };
    },

    computed: {
        restante() {
            return Math.max(0, Math.ceil((this.janelaAte - this.agora) / 1000));
        },

        // "1:45"
        relogio() {
            const segundos = this.restante % 60;
            return `${Math.floor(this.restante / 60)}:${String(segundos).padStart(2, '0')}`;
        },

        motivoCompleto() {
            return this.motivo.trim().length >= this.revelacao.motivoMinimo;
        },

        janelaMinutos() {
            return Math.round(this.revelacao.segundos / 60);
        },
    },

    watch: {
        leitura() {
            this.atualizar();
        },
    },

    created() {
        this.carregar();
    },

    beforeUnmount() {
        this.fecharJanela();
    },

    methods: {
        async buscar(pagina) {
            const url = Utils.createUrl('govbr-satisfaction-requests', 'dispatches', { id: this.requestId, pagina });
            const response = await fetch(url);
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(data.error || this.text('erroAoCarregar'));
            }

            return data;
        },

        async carregar() {
            this.carregando = true;
            this.erro = null;

            try {
                const data = await this.buscar(1);

                this.envios = data.envios;
                this.pagina = data.pagina;
                this.paginas = data.paginas;

                if (!this.envios.length) {
                    await this.carregarPrevia();
                }
            } catch (error) {
                this.erro = error.message || this.text('erroAoCarregar');
            } finally {
                this.carregando = false;
            }
        },

        // falha ao paginar mantém o que já está na tela
        async carregarMais() {
            this.carregandoMais = true;

            try {
                const data = await this.buscar(this.pagina + 1);

                this.envios = this.envios.concat(data.envios);
                this.pagina = data.pagina;
                this.paginas = data.paginas;
            } catch (error) {
                this.messages.error(error.message || this.text('erroAoCarregar'));
            } finally {
                this.carregandoMais = false;
            }
        },

        // relê as páginas carregadas mantendo os acordeões abertos
        async atualizar() {
            if (this.carregando || this.carregandoMais) {
                return;
            }

            try {
                let envios = [];
                let data = null;

                for (let pagina = 1; pagina <= Math.max(1, this.pagina); pagina++) {
                    data = await this.buscar(pagina);
                    envios = envios.concat(data.envios);
                }

                this.envios = envios;
                this.paginas = data.paginas;
                this.erro = null;
            } catch (error) {
            }
        },

        async carregarPrevia() {
            const response = await fetch(Utils.createUrl('govbr-satisfaction-requests', 'payload', { id: this.requestId }));
            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                this.previa = { payload: data.payload, motivo: data.motivo };
            }
        },

        podeRevelar(tentativa) {
            return tentativa.revelavel && this.revelacao.disponivel && this.revelacao.autorizado;
        },

        async postar(acao, corpo) {
            const response = await fetch(Utils.createUrl('govbr-satisfaction-requests', acao), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                cache: 'no-store',
                body: JSON.stringify(corpo),
            });

            return [response, await response.json().catch(() => ({}))];
        },

        // sem janela aberta, pede o motivo e repete depois
        async pedir(tentativa, acao) {
            if (this.revelando) {
                return;
            }

            if (this.restante <= 0) {
                this.pedirMotivo(tentativa, acao);
                return;
            }

            this.revelando = tentativa.id;

            try {
                const [response, data] = await this.postar('reveal', { tentativa: tentativa.id, acao });

                // janela vencida ou limite atingido no servidor
                if (response.status === 403 && data.janela === false) {
                    if (data.limite) {
                        this.messages.alert(data.error);
                    }

                    this.fecharJanela();
                    this.pedirMotivo(tentativa, acao);
                    return;
                }

                if (!response.ok) {
                    this.messages.error(data.error || this.text('revelarErro'));
                    return;
                }

                this.abrirJanela(data.segundos, data.restantes);

                if (acao === 'copiar') {
                    await this.copiar(this.json(data.payload));
                } else {
                    this.revelados = { ...this.revelados, [tentativa.id]: data.payload };
                }
            } catch (error) {
                this.messages.error(this.text('revelarErro'));
            } finally {
                this.revelando = null;
            }
        },

        pedirMotivo(tentativa, acao) {
            this.pendente = { tentativa, acao };
            this.motivo = '';
            this.$refs.motivo.open();
        },

        async liberar(modal) {
            this.liberando = true;

            try {
                const [response, data] = await this.postar('unlockReveal', { motivo: this.motivo.trim() });

                if (!response.ok) {
                    this.messages.error(data.error || this.text('revelarErro'));
                    return;
                }

                this.abrirJanela(data.segundos, data.restantes);

                const pendente = this.pendente;
                modal.close();

                if (pendente) {
                    await this.pedir(pendente.tentativa, pendente.acao);
                }
            } catch (error) {
                this.messages.error(this.text('revelarErro'));
            } finally {
                this.liberando = false;
                this.motivo = '';
            }
        },

        // fim pelo relógio deste navegador, a partir dos segundos restantes no servidor
        abrirJanela(segundos, restantes) {
            this.janelaAte = Date.now() + segundos * 1000;
            this.restantes = restantes;
            this.agora = Date.now();

            if (!this.relogioId) {
                this.relogioId = setInterval(() => {
                    this.agora = Date.now();

                    if (this.agora >= this.janelaAte) {
                        this.fecharJanela();
                    }
                }, 1000);
            }
        },

        // janela fechada: os dados reais saem da tela e da memória
        fecharJanela() {
            clearInterval(this.relogioId);
            this.relogioId = null;
            this.revelados = {};
            this.janelaAte = 0;
        },

        ocultar(tentativa) {
            const revelados = { ...this.revelados };
            delete revelados[tentativa.id];
            this.revelados = revelados;
        },

        falhou(tentativa) {
            return ['retentar', 'recusado'].includes(tentativa.situacao);
        },

        icone(situacao) {
            return GOVBR_DISPATCHES_ICONES[situacao] ?? 'govbr-satisfaction-pending';
        },

        rotulo(situacao) {
            return this.text('situacao-' + situacao);
        },

        // "19/09/2026, 16:18:02"
        data(timestamp) {
            if (!timestamp) {
                return '';
            }

            return new McDate(new Date(timestamp * 1000)).format({ dateStyle: 'short', timeStyle: 'medium' });
        },

        json(valor) {
            return JSON.stringify(valor, null, 2);
        },

        // resposta JSON indentada; texto puro como veio
        corpo(tentativa) {
            let corpo = tentativa.resposta;

            try {
                corpo = JSON.stringify(JSON.parse(corpo), null, 2);
            } catch (e) {
            }

            return tentativa.respostaCortada ? corpo + '\n\n' + this.text('respostaCortada') : corpo;
        },

        async copiar(conteudo) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(conteudo);
                } else if (!this.copiarPorSelecao(conteudo)) {
                    throw new Error();
                }

                this.messages.success(this.text('copiado'));
            } catch (error) {
                this.messages.error(this.text('copiarErro'));
            }
        },

        // sem contexto seguro: campo fora da tela e copy do documento
        copiarPorSelecao(conteudo) {
            const campo = document.createElement('textarea');
            campo.value = conteudo;
            campo.setAttribute('readonly', '');
            campo.style.position = 'fixed';
            campo.style.top = '-9999px';
            document.body.appendChild(campo);
            campo.select();

            let copiou = false;

            try {
                copiou = document.execCommand('copy');
            } catch (error) {
                copiou = false;
            }

            document.body.removeChild(campo);

            return copiou;
        },
    },
});
