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
    },

    setup() {
        const text = Utils.getTexts('govbr-satisfaction-dispatches');
        const messages = useMessages();

        // substitui cada `%s` do texto pelo próximo argumento
        const fmt = (chave, ...valores) => valores.reduce((s, v) => s.replace('%s', v), text(chave));

        return { text, fmt, messages };
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
        };
    },

    created() {
        this.carregar();
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

        async carregarPrevia() {
            const response = await fetch(Utils.createUrl('govbr-satisfaction-requests', 'payload', { id: this.requestId }));
            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                this.previa = { payload: data.payload, motivo: data.motivo };
            }
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
