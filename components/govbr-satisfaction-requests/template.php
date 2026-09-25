<?php
/**
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    govbr-satisfaction-dispatches
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

            <form class="govbr-satisfaction__filtros" @submit.prevent>
                <div class="field">
                    <label for="govbr-satisfaction-busca"><?= i::__('Buscar') ?></label>
                    <input
                        id="govbr-satisfaction-busca"
                        type="search"
                        v-model="filtros.busca"
                        :placeholder="text('buscar')"
                        @input="buscar">
                </div>

                <div class="field">
                    <label for="govbr-satisfaction-servico"><?= i::__('Serviço') ?></label>
                    <select id="govbr-satisfaction-servico" v-model="filtros.servico" @change="filtrar">
                        <option value="">{{ text('todos') }}</option>
                        <option v-for="servico in status.servicos" :key="servico.id" :value="servico.id">
                            {{ text(servico.chave) }}
                        </option>
                    </select>
                </div>
            </form>

            <div class="govbr-satisfaction__barra">
            <div class="govbr-satisfaction__pilulas" role="group" :aria-label="text('filtrarSituacao')">
                <button
                    type="button"
                    class="govbr-satisfaction__pilula"
                    :class="{'govbr-satisfaction__pilula--ativa': !filtros.situacao}"
                    :aria-pressed="!filtros.situacao"
                    @click="escolherSituacao('')">
                    {{ text('todas') }} <span>{{ totalGeral }}</span>
                </button>

                <button
                    v-for="situacao in situacoes"
                    :key="situacao"
                    type="button"
                    class="govbr-satisfaction__pilula"
                    :class="{'govbr-satisfaction__pilula--ativa': filtros.situacao === situacao}"
                    :aria-pressed="filtros.situacao === situacao"
                    @click="escolherSituacao(situacao)">
                    {{ text(situacao) }} <span>{{ totais[situacao] || 0 }}</span>
                </button>
            </div>

            <div class="govbr-satisfaction__monitor">
                <span class="govbr-satisfaction__hint" v-if="tempoReal && atualizadoEm" aria-live="polite">
                    {{ fmt('atualizadoEm', hora(atualizadoEm)) }}
                </span>

                <button type="button" class="button button--primary-outline button--sm" v-if="!tempoReal" :disabled="carregando" @click="atualizar">
                    {{ text('atualizar') }}
                </button>

                <!-- sólido enquanto monitora -->
                <button
                    type="button"
                    :class="['button', 'button--sm', tempoReal ? 'button--primary' : 'button--primary-outline']"
                    :aria-pressed="tempoReal"
                    @click="alternarTempoReal">
                    <span class="govbr-satisfaction__pulso" v-if="tempoReal"></span>
                    {{ tempoReal ? text('pausar') : text('tempoReal') }}
                </button>
            </div>
            </div>

            <!-- seleção: persiste entre páginas e filtros -->
            <div class="govbr-satisfaction__acoes" v-if="registros.length || totalSelecionadas">
                <label class="govbr-satisfaction__selecionar-todas">
                    <input
                        type="checkbox"
                        :checked="paginaToda"
                        :disabled="!selecionaveis.length"
                        @change="alternarPagina">
                    {{ text('selecionarTodas') }}
                </label>

                <div class="govbr-satisfaction__resumo">
                    <span class="govbr-satisfaction__contador">{{ fmt('selecionadas', totalSelecionadas) }}</span>

                    <span class="govbr-satisfaction__limite" v-if="acimaDoTeto">
                        {{ fmt('acimaDoTeto', status.loteMaximo) }}
                    </span>

                    <button type="button" class="button button--text button--sm" v-if="totalSelecionadas" @click="limparSelecao">
                        {{ text('limparSelecao') }}
                    </button>

                    <mc-modal classes="govbr-satisfaction__modal" :title="text('devolverSelecionadasTitulo')">
                        <template #default>
                            <p>{{ fmt('devolverTodasConfirmacao', totalSelecionadas, status.loteIntervalo, duracao(Math.max(0, totalSelecionadas - 1) * status.loteIntervalo)) }}</p>
                        </template>

                        <template #actions="modal">
                            <button class="button button--text button--md" @click="modal.close()">
                                <?= i::__('Cancelar') ?>
                            </button>
                            <button
                                class="button button--primary button--md"
                                :class="{disabled: devolvendoSelecionadas}"
                                :disabled="devolvendoSelecionadas"
                                @click="devolverSelecionadas(modal)">
                                <?= i::__('Confirmar') ?>
                            </button>
                        </template>

                        <template #button="modal">
                            <button
                                type="button"
                                class="button button--primary button--sm govbr-satisfaction__bulk"
                                :disabled="!podeDevolverSelecionadas"
                                @click="modal.open()">
                                <mc-icon name="govbr-satisfaction-requeue"></mc-icon>
                                {{ fmt('devolverSelecionadas', totalSelecionadas) }}
                            </button>
                        </template>
                    </mc-modal>
                </div>
            </div>

            <!-- lote: todas as recusadas do filtro, além da página -->
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
                <ul class="govbr-satisfaction__lista">
                    <li
                        v-for="registro in registros"
                        :key="registro.id"
                        class="govbr-request"
                        :class="{'govbr-request--aberta': abertos[registro.id]}">
                        <div class="govbr-request__linha">
                            <label class="govbr-request__selecao">
                                <input
                                    type="checkbox"
                                    :checked="!!selecionados[registro.id]"
                                    :disabled="!selecionavel(registro)"
                                    :aria-label="fmt('selecionar', registro.id)"
                                    @change="alternarSelecao(registro)">
                            </label>

                            <div class="govbr-request__info">
                                <h4 class="govbr-request__servico">{{ rotuloServico(registro.servico) }}</h4>

                                <p class="govbr-request__meta">
                                    <span>{{ registro.pessoa }} <small>#{{ registro.userId }}</small></span>
                                    <span v-if="registro.origem">{{ registro.origem }}</span>
                                    <span class="govbr-satisfaction__muted" v-else>{{ text('cadastroDaConta') }}</span>
                                    <span>{{ fmt('registradaEm', quando(registro.registrada)) }}</span>
                                </p>

                                <p class="govbr-request__detalhe" v-if="registro.detalhe" :title="registro.detalhe">
                                    {{ resumo(registro.detalhe) }}
                                </p>
                            </div>

                            <div class="govbr-request__situacao">
                                <span class="mc-status" :class="'mc-status--' + tom(registro.situacao)">
                                    <mc-icon name="dot"></mc-icon>
                                    <span>{{ text(registro.situacao) }}</span>
                                </span>

                                <small v-if="registro.disparada">{{ fmt('disparadaEm', quando(registro.disparada)) }}</small>
                            </div>

                            <div class="govbr-request__acoes">
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
                                            type="button"
                                            class="button button--primary-noborder button--sm govbr-satisfaction__acao"
                                            :title="text(aguardaRetentativa(registro) ? 'tentarAgora' : 'devolver')"
                                            :aria-label="text(aguardaRetentativa(registro) ? 'tentarAgora' : 'devolver')"
                                            @click="modal.open()">
                                            <mc-icon :name="aguardaRetentativa(registro) ? 'govbr-satisfaction-retry' : 'govbr-satisfaction-requeue'"></mc-icon>
                                        </button>
                                    </template>
                                </mc-modal>

                                <button
                                    type="button"
                                    class="button button--primary-noborder button--sm govbr-satisfaction__acao"
                                    :title="text('historico')"
                                    :aria-label="text('historico')"
                                    :aria-expanded="!!abertos[registro.id]"
                                    :aria-controls="'govbr-satisfaction-historico-' + registro.id"
                                    @click="alternarHistorico(registro)">
                                    <mc-icon name="govbr-satisfaction-history"></mc-icon>
                                </button>
                            </div>
                        </div>

                        <div class="govbr-request__historico" v-if="abertos[registro.id]" :id="'govbr-satisfaction-historico-' + registro.id">
                            <govbr-satisfaction-dispatches
                                :key="registro.id + '-' + (versoes[registro.id] || 0)"
                                :request-id="registro.id"
                                :revelacao="status.revelacao"
                                :tick="tick">
                            </govbr-satisfaction-dispatches>
                        </div>
                    </li>
                </ul>

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
