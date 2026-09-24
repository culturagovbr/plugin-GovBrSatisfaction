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
        {{ text('faltandoConfig').replace('%s', status.faltando.join(', ')) }}
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

            <!--
                O spinner só aparece quando não há nada na tela. Escondendo a
                tabela a cada "Carregar Mais", a lista sumiria e voltaria a cada
                avanço — o contrário do que a acumulação existe para fazer, que é
                não perder o lugar. Enquanto carrega, o botão fica desabilitado.
            -->
            <mc-loading :condition="carregando && !registros.length"></mc-loading>

            <!--
                Lista vazia por filtro e lista vazia de verdade usam o mesmo
                bloco: nos dois casos não há nada de errado acontecendo, e um
                aviso colorido daria a essa situação um peso que ela não tem. O
                que muda é a mensagem, e a saída oferecida.

                A condição é escrita por inteiro em vez de `v-else-if`: o irmão
                acima é um componente com a prop `condition`, não uma diretiva,
                e encadear nele deixaria o `v-else-if` sem `v-if` adjacente —
                o Vue não resolveria a diretiva e o bloco apareceria sempre,
                empilhado sobre a tabela cheia.
            -->
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

                                    <!--
                                        O que a API respondeu, quando há algo a
                                        dizer. Sem isto, "Recusado" não explica
                                        se o problema é do payload ou do BSC, e a
                                        resposta só existiria no log do servidor.

                                        Só a mensagem: o código HTTP fica na
                                        coluna, para quem for investigar, mas na
                                        tabela seria ruído — "Avaliação já
                                        enviada" diz o que importa, "500" não.
                                    -->
                                    <small class="govbr-satisfaction__detalhe" v-if="registro.detalhe" :title="registro.detalhe">
                                        {{ resumo(registro.detalhe) }}
                                    </small>
                                </td>

                                <td class="govbr-satisfaction__date">{{ quando(registro.registrada) }}</td>
                                <td class="govbr-satisfaction__date">{{ quando(registro.disparada) }}</td>

                                <td class="govbr-satisfaction__table-actions">
                                    <mc-modal classes="govbr-satisfaction__modal" :title="text('payloadTitulo')">
                                        <template #button="{open}">
                                            <button class="button button--primary-outline button--sm" @click="verPayload(registro.id, open)">
                                                {{ text('ver') }}
                                            </button>
                                        </template>

                                        <template #default>
                                            <!--
                                                O que saiu vem antes do que voltou:
                                                a leitura natural é "enviei isto,
                                                recebi aquilo", e em auditoria o
                                                payload é o documento principal.
                                            -->
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

                                                <!--
                                                    Só na prévia. Enviado sempre
                                                    tem cópia: sendPayload é
                                                    gravado no mesmo save que
                                                    marca STATUS_SENT, então não
                                                    existe enviado sem ela.
                                                -->
                                                <p class="govbr-satisfaction__nota" v-if="payloadReconstruido">{{ text('payloadPrevia') }}</p>

                                                <mc-loading :condition="carregandoPayload"></mc-loading>

                                                <p class="govbr-satisfaction__nota" v-if="!carregandoPayload && payloadMotivo">{{ payloadMotivo }}</p>

                                                <pre class="govbr-satisfaction__corpo" v-else-if="!carregandoPayload && payload">{{ formatarJson(payload) }}</pre>
                                            </div>

                                            <div class="govbr-satisfaction__bloco" v-if="registro.detalhe || registro.resposta">
                                                <h4>
                                                    {{ text('respostaTitulo') }}
                                                    <span class="govbr-satisfaction__http" v-if="registro.httpStatus">HTTP {{ registro.httpStatus }}</span>

                                                    <button
                                                        type="button"
                                                        class="govbr-satisfaction__copiar"
                                                        v-if="registro.resposta"
                                                        @click="copiar(formatarResposta(registro.resposta), 'resposta-' + registro.id)">
                                                        {{ copiado === 'resposta-' + registro.id ? text('copiado') : text('copiar') }}
                                                    </button>
                                                </h4>

                                                <p class="govbr-satisfaction__nota" v-if="registro.detalhe">{{ registro.detalhe }}</p>

                                                <pre class="govbr-satisfaction__corpo" v-if="registro.resposta">{{ formatarResposta(registro.resposta) }}</pre>
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
                        {{ text('contagem').replace('%1', registros.length).replace('%2', total) }}
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
