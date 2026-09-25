<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-alert
    mc-card
    mc-icon
    mc-loading
    mc-modal
    mc-status
');
?>
<div class="govbr-satisfaction">
    <mc-alert type="warning" v-if="status.devMode">
        {{ text('modoDesenvolvimento') }}
    </mc-alert>

    <mc-alert type="danger" v-if="status.faltando.length">
        {{ fmt('faltandoConfig', status.faltando.join(', ')) }}
    </mc-alert>

    <mc-card>
        <template #title>
            <h3><?= i::__('Pesquisas disparadas') ?></h3>
        </template>

        <template #content>
            <p class="govbr-satisfaction__lead">{{ text('disparadoExplicacao') }}</p>

            <div class="govbr-satisfaction__toolbar">
                <div class="govbr-satisfaction__totals">
                    <button
                        v-for="situacao in situacoes"
                        :key="situacao"
                        type="button"
                        class="govbr-satisfaction__total"
                        :class="{'govbr-satisfaction__total--active': filtros.situacao === situacao}"
                        :aria-pressed="filtros.situacao === situacao"
                        @click="alternarSituacao(situacao)">
                        <strong>{{ totais[situacao] || 0 }}</strong>
                        <span>{{ text(situacao) }}</span>
                    </button>
                </div>

                <div class="govbr-satisfaction__filters">
                    <div class="field">
                        <label for="govbr-satisfaction-servico"><?= i::__('Serviço') ?></label>
                        <select id="govbr-satisfaction-servico" v-model="filtros.servico" @change="filtrar">
                            <option value="">{{ text('todos') }}</option>
                            <option v-for="servico in status.servicos" :key="servico.id" :value="servico.id">
                                {{ text(servico.chave) }}
                            </option>
                        </select>
                    </div>

                </div>
            </div>

            <!-- lote: barra contextual, só com o filtro de recusadas ativo e algo na lista -->
            <div class="govbr-satisfaction__lote" v-if="podeDevolverTodas">
                <span>{{ fmt('loteResumo', total) }}</span>
                <mc-modal classes="govbr-satisfaction__modal" :title="text('devolverTodasTitulo')">
                    <template #default>
                        <p>{{ fmt('devolverTodasConfirmacao', loteTamanho, status.loteIntervalo, duracao(Math.max(0, loteTamanho - 1) * status.loteIntervalo)) }}</p>
                        <p class="govbr-satisfaction__nota" v-if="total > loteTamanho">{{ fmt('devolverTodasTeto', loteTamanho, total) }}</p>
                    </template>

                    <template #actions="modal">
                        <button class="button button--text button--md" @click="modal.close()">
                            <?= i::__('Cancelar') ?>
                        </button>
                        <button
                            class="button button--primary button--md"
                            :class="{disabled: devolvendoTodas}"
                            :disabled="devolvendoTodas"
                            @click="devolverTodas(modal)">
                            <?= i::__('Confirmar') ?>
                        </button>
                    </template>

                    <template #button="modal">
                        <button class="button button--primary-outline button--sm govbr-satisfaction__bulk" @click="modal.open()">
                            <mc-icon name="govbr-satisfaction-requeue"></mc-icon>
                            {{ fmt('devolverTodas', total) }}
                        </button>
                    </template>
                </mc-modal>
            </div>

            <mc-loading :condition="carregando && !registros.length"></mc-loading>

            <!-- lista vazia -->
            <div class="govbr-satisfaction__empty" v-if="!registros.length && !carregando">
                <mc-icon name="govbr-satisfaction"></mc-icon>

                <template v-if="filtroAtivo">
                    <p>{{ text('nenhumComFiltro') }}</p>
                    <button class="button button--primary-outline button--sm" @click="limparFiltros">
                        {{ text('limparFiltros') }}
                    </button>
                </template>

                <template v-else>
                    <p><?= i::__('Nenhuma solicitação registrada.') ?></p>
                    <p class="govbr-satisfaction__hint">{{ text('vazioDica') }}</p>
                </template>
            </div>

            <template v-if="registros.length">
                <div class="govbr-satisfaction__table-wrapper">
                    <table class="govbr-satisfaction__table">
                        <thead>
                            <tr>
                                <th><?= i::__('Serviço') ?></th>
                                <th><?= i::__('Pessoa') ?></th>
                                <th><?= i::__('Origem') ?></th>
                                <th><?= i::__('Situação') ?></th>
                                <th><?= i::__('Registrada') ?></th>
                                <th><?= i::__('Disparada') ?></th>
                                <th class="govbr-satisfaction__table-actions"></th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-for="registro in registros" :key="registro.id">
                                <td>{{ rotuloServico(registro.servico) }}</td>

                                <td>
                                    {{ registro.pessoa }}
                                    <small>#{{ registro.userId }}</small>
                                </td>

                                <td>
                                    <span v-if="registro.origem">{{ registro.origem }}</span>
                                    <span class="govbr-satisfaction__muted" v-else>{{ text('cadastroDaConta') }}</span>
                                </td>

                                <td>
                                    <span class="mc-status" :class="'mc-status--' + tom(registro.situacao)">
                                        <mc-icon name="dot"></mc-icon>
                                        <span>{{ text(registro.situacao) }}</span>
                                    </span>

                                    <small class="govbr-satisfaction__detalhe" v-if="registro.detalhe" :title="registro.detalhe">
                                        {{ resumo(registro.detalhe) }}
                                    </small>
                                </td>

                                <td class="govbr-satisfaction__date">{{ quando(registro.registrada) }}</td>
                                <td class="govbr-satisfaction__date">{{ quando(registro.disparada) }}</td>

                                <td class="govbr-satisfaction__table-actions">
                                    <mc-modal classes="govbr-satisfaction__modal" :title="text(aguardaRetentativa(registro) ? 'tentarAgoraTitulo' : 'devolverTitulo')" v-if="podeDevolver(registro)">
                                        <template #default>
                                            <p v-if="aguardaRetentativa(registro)">{{ text('tentarAgoraConfirmacao') }}</p>
                                            <p v-else>{{ text(registro.situacao === 'sem-cpf' ? 'devolverConfirmacaoSemCpf' : 'devolverConfirmacao') }}</p>
                                            <p class="govbr-satisfaction__nota" v-if="registro.situacao === 'recusado'">
                                                {{ fmt('tentativas', registro.tentativas) }}
                                            </p>
                                        </template>

                                        <template #actions="modal">
                                            <button class="button button--text button--md" @click="modal.close()">
                                                <?= i::__('Cancelar') ?>
                                            </button>
                                            <button
                                                class="button button--primary button--md"
                                                :class="{disabled: devolvendo === registro.id}"
                                                :disabled="devolvendo === registro.id"
                                                @click="devolverAFila(registro, modal)">
                                                <?= i::__('Confirmar') ?>
                                            </button>
                                        </template>

                                        <template #button="modal">
                                            <button
                                                class="button button--primary-noborder button--sm govbr-satisfaction__acao"
                                                :title="text(aguardaRetentativa(registro) ? 'tentarAgora' : 'devolver')"
                                                :aria-label="text(aguardaRetentativa(registro) ? 'tentarAgora' : 'devolver')"
                                                @click="modal.open()">
                                                <mc-icon :name="aguardaRetentativa(registro) ? 'govbr-satisfaction-retry' : 'govbr-satisfaction-requeue'"></mc-icon>
                                            </button>
                                        </template>
                                    </mc-modal>

                                    <mc-modal classes="govbr-satisfaction__modal" :title="text('payloadTitulo')">
                                        <template #button="{open}">
                                            <button
                                                class="button button--primary-noborder button--sm govbr-satisfaction__acao"
                                                :title="text('ver')"
                                                :aria-label="text('ver')"
                                                @click="verPayload(registro.id, open)">
                                                <mc-icon name="eye-view"></mc-icon>
                                            </button>
                                        </template>

                                        <template #actions="modal">
                                            <button class="button button--primary button--md" @click="modal.close()">
                                                <?= i::__('Fechar') ?>
                                            </button>
                                        </template>

                                        <template #default>
                                            <!-- anúncio para leitor de tela -->
                                            <span class="govbr-satisfaction__sr-only" aria-live="polite">{{ copiado ? text('copiado') : '' }}</span>

                                            <div class="govbr-satisfaction__bloco">
                                                <h4>
                                                    {{ text('payloadTitulo') }}
                                                    <span class="govbr-satisfaction__http govbr-satisfaction__http--aviso" v-if="payloadReconstruido">
                                                        {{ text('payloadPreviaSelo') }}
                                                    </span>

                                                    <button
                                                        type="button"
                                                        class="govbr-satisfaction__copiar"
                                                        v-if="payload && !carregandoPayload"
                                                        @click="copiar(formatarJson(payload), 'payload-' + registro.id)">
                                                        {{ copiado === 'payload-' + registro.id ? text('copiado') : text('copiar') }}
                                                    </button>
                                                </h4>

                                                <p class="govbr-satisfaction__nota" v-if="payloadReconstruido">{{ text('payloadPrevia') }}</p>

                                                <mc-loading :condition="carregandoPayload"></mc-loading>

                                                <p class="govbr-satisfaction__nota" v-if="!carregandoPayload && payloadMotivo">{{ payloadMotivo }}</p>

                                                <pre class="govbr-satisfaction__corpo" v-else-if="!carregandoPayload && payload">{{ formatarJson(payload) }}</pre>
                                            </div>

                                            <div class="govbr-satisfaction__bloco" v-if="registro.detalhe || resposta">
                                                <h4>
                                                    {{ text(registro.situacao === 'pendente' ? 'ultimaRespostaTitulo' : 'respostaTitulo') }}
                                                    <span class="govbr-satisfaction__http" v-if="registro.httpStatus">HTTP {{ registro.httpStatus }}</span>

                                                    <button
                                                        type="button"
                                                        class="govbr-satisfaction__copiar"
                                                        v-if="resposta"
                                                        @click="copiar(formatarResposta(resposta), 'resposta-' + registro.id)">
                                                        {{ copiado === 'resposta-' + registro.id ? text('copiado') : text('copiar') }}
                                                    </button>
                                                </h4>

                                                <p class="govbr-satisfaction__nota" v-if="registro.detalhe">{{ registro.detalhe }}</p>

                                                <pre class="govbr-satisfaction__corpo" v-if="corpoAcrescenta(registro)">{{ formatarResposta(resposta) }}</pre>
                                            </div>
                                        </template>
                                    </mc-modal>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="govbr-satisfaction__more" v-if="pagina < paginas">
                    <span class="govbr-satisfaction__hint">
                        {{ fmt('contagem', registros.length, total) }}
                    </span>

                    <button
                        class="button button--large button--primary-outline"
                        :disabled="carregando"
                        @click="carregarMais">
                        <?= i::__('Carregar Mais') ?>
                    </button>
                </div>
            </template>
        </template>
    </mc-card>
</div>
