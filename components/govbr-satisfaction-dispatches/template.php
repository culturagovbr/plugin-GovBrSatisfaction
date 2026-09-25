<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

$this->import('
    mc-accordion
    mc-alert
    mc-icon
    mc-loading
');
?>
<div class="govbr-dispatches">
    <mc-loading :condition="carregando"></mc-loading>

    <template v-if="!carregando">
        <mc-alert v-if="erro" type="danger" role="alert">{{ erro }}</mc-alert>

        <!-- sem envio: prévia do que será enviado -->
        <div v-else-if="!envios.length" class="govbr-dispatches__vazio">
            <p>{{ text('semEnvio') }}</p>

            <p class="govbr-dispatches__nota" v-if="previa.motivo">{{ previa.motivo }}</p>

            <mc-accordion class="govbr-dispatches__gaveta" v-if="previa.payload">
                <template #title>{{ text('previa') }}</template>
                <template #content>
                    <p class="govbr-dispatches__nota">{{ text('previaNota') }}</p>
                    <div class="govbr-dispatches__codigo">
                        <button class="govbr-dispatches__copiar" type="button" @click="copiar(json(previa.payload))">
                            <mc-icon name="govbr-satisfaction-copy"></mc-icon>
                            <span>{{ text('copiar') }}</span>
                        </button>
                        <pre class="govbr-dispatches__json" tabindex="0" :aria-label="text('previa')">{{ json(previa.payload) }}</pre>
                    </div>
                </template>
            </mc-accordion>
        </div>

        <template v-else>
            <div v-for="envio in envios" :key="envio.uuid" class="govbr-dispatches__envio">
                <mc-accordion>
                    <template #title>
                        <span class="govbr-dispatches__titulo">
                            <mc-icon
                                class="govbr-dispatches__status"
                                :class="'govbr-dispatches__status--' + envio.situacao"
                                :name="icone(envio.situacao)"
                                :title="rotulo(envio.situacao)"
                                :aria-label="rotulo(envio.situacao)"
                            ></mc-icon>
                            <code class="govbr-dispatches__uuid">{{ envio.uuid }}</code>
                            <small class="govbr-dispatches__muted">{{ data(envio.criadoEm) }}</small>
                            <small class="govbr-dispatches__muted">{{ text('origem-' + envio.origem) }}</small>
                            <small class="govbr-dispatches__muted" v-if="envio.autor">{{ fmt('porUsuario', envio.autor.id) }}</small>
                        </span>
                    </template>

                    <template #content>
                        <p v-if="!envio.tentativas.length" class="govbr-dispatches__nota">
                            {{ text(envio.situacao === 'pendente' ? 'aguardandoTentativa' : 'semTentativa') }}
                        </p>

                        <mc-accordion
                            v-for="tentativa in envio.tentativas"
                            :key="envio.uuid + '-' + tentativa.numero"
                            class="govbr-dispatches__tentativa">
                            <template #title>
                                <span class="govbr-dispatches__titulo">
                                    <mc-icon
                                        class="govbr-dispatches__status"
                                        :class="'govbr-dispatches__status--' + tentativa.situacao"
                                        :name="icone(tentativa.situacao)"
                                        :title="rotulo(tentativa.situacao)"
                                        :aria-label="rotulo(tentativa.situacao)"
                                    ></mc-icon>
                                    <strong>{{ fmt('tentativa', tentativa.numero, tentativa.maximo) }}</strong>
                                    <small v-if="tentativa.httpStatus">HTTP {{ tentativa.httpStatus }}</small>
                                    <small v-if="tentativa.duracaoMs !== null">{{ fmt('duracao', tentativa.duracaoMs) }}</small>
                                </span>
                            </template>

                            <template #content>
                                <dl class="govbr-dispatches__meta">
                                    <dt>{{ text('enviadoEm') }}</dt>
                                    <dd>{{ data(tentativa.enviadoEm) }}</dd>

                                    <template v-if="tentativa.endpoint">
                                        <dt>{{ text('endpoint') }}</dt>
                                        <dd><code>{{ tentativa.metodo }} {{ tentativa.endpoint }}</code></dd>
                                    </template>

                                    <template v-if="tentativa.detalhe && !falhou(tentativa)">
                                        <dt>{{ text('resumo') }}</dt>
                                        <dd>{{ tentativa.detalhe }}</dd>
                                    </template>
                                </dl>

                                <mc-alert v-if="tentativa.detalhe && falhou(tentativa)" type="danger">
                                    {{ tentativa.detalhe }}
                                </mc-alert>

                                <mc-accordion class="govbr-dispatches__gaveta">
                                    <template #title>{{ text('payload') }}</template>
                                    <template #content>
                                        <div class="govbr-dispatches__codigo" v-if="tentativa.payload">
                                            <button class="govbr-dispatches__copiar" type="button" @click="copiar(json(tentativa.payload))">
                                                <mc-icon name="govbr-satisfaction-copy"></mc-icon>
                                                <span>{{ text('copiar') }}</span>
                                            </button>
                                            <pre class="govbr-dispatches__json" tabindex="0" :aria-label="text('payload')">{{ json(tentativa.payload) }}</pre>
                                        </div>

                                        <p class="govbr-dispatches__nota" v-else>{{ text('semPayload') }}</p>
                                    </template>
                                </mc-accordion>

                                <mc-accordion class="govbr-dispatches__gaveta">
                                    <template #title>{{ text('resposta') }}</template>
                                    <template #content>
                                        <!-- cabeçalhos antes do corpo -->
                                        <div class="govbr-dispatches__codigo" v-if="tentativa.cabecalhos?.length">
                                            <button class="govbr-dispatches__copiar" type="button" @click="copiar(tentativa.cabecalhos.join('\n'))">
                                                <mc-icon name="govbr-satisfaction-copy"></mc-icon>
                                                <span>{{ text('copiar') }}</span>
                                            </button>
                                            <pre class="govbr-dispatches__json" tabindex="0" :aria-label="text('cabecalhos')">{{ tentativa.cabecalhos.join('\n') }}</pre>
                                        </div>

                                        <div class="govbr-dispatches__codigo" v-if="tentativa.resposta">
                                            <button class="govbr-dispatches__copiar" type="button" @click="copiar(corpo(tentativa))">
                                                <mc-icon name="govbr-satisfaction-copy"></mc-icon>
                                                <span>{{ text('copiar') }}</span>
                                            </button>
                                            <pre class="govbr-dispatches__json" tabindex="0" :aria-label="text('resposta')">{{ corpo(tentativa) }}</pre>
                                        </div>

                                        <p class="govbr-dispatches__nota" v-if="!tentativa.resposta && !tentativa.cabecalhos?.length">
                                            {{ text('semResposta') }}
                                        </p>
                                    </template>
                                </mc-accordion>
                            </template>
                        </mc-accordion>
                    </template>
                </mc-accordion>
            </div>

            <button
                v-if="pagina < paginas"
                class="button button--primary-outline button--sm govbr-dispatches__mais"
                type="button"
                :disabled="carregandoMais"
                :aria-busy="carregandoMais"
                @click="carregarMais">
                {{ text('carregarMais') }}
            </button>
        </template>
    </template>
</div>
