<?php

namespace GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Client;
use GovBrSatisfaction\Bsc\FixtureClient;
use GovBrSatisfaction\Bsc\HttpClient;
use GovBrSatisfaction\Jobs\SendSatisfactionRequestJob;
use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\i;

/**
 * Avaliação de satisfação dos serviços do Mapa da Cultura no gov.br.
 *
 * Concluído um dos seis serviços do MinC no Portal de Serviços, o Mapa
 * registra a solicitação e um job avisa o BSC; o gov.br envia o e-mail com o
 * questionário, e a avaliação acontece fora daqui. Não há tela nem
 * consentimento nesta plataforma — só o painel de consulta.
 *
 * O gatilho é a publicação, não a criação: entidade nasce rascunho, e pesquisar
 * sobre rascunho abandonado é perguntar por serviço não concluído.
 * "Cadastrar-se" é a exceção, e dispara na confirmação do e-mail.
 *
 * @package GovBrSatisfaction
 */
class Plugin extends \MapasCulturais\Plugin
{
    /** Entidades cuja publicação dispara uma solicitação. */
    const PUBLISHED_ENTITIES = [
        'Agent' => 'coletivo',
        'Event' => 'evento',
        'Space' => 'espaco',
        'Project' => 'projeto',
        'Opportunity' => 'oportunidade',
    ];

    private ?Services\SatisfactionRegistry $registry = null;

    private ?Services\SatisfactionSender $sender = null;

    function __construct(array $config = [])
    {
        $config += [
            // Desligar é declarado em plugins.php, como fazem Security e
            // Metabase — e não por variável de ambiente.
            'enabled' => true,

            // Transporte explícito; nulo significa "escolher pelo ambiente".
            'client' => null,

            // Ausente equivale a TRUE, e não a FALSE: a falha segura é não
            // enviar. Um ambiente mal configurado que deixa de disparar é um
            // incômodo; um que envia CPF real ao gov.br por engano é incidente
            // de dados.
            'devMode' => self::boolEnv('AVALIACAO_DEV_MODE', true),

            'bscUrl' => env('AVALIACAO_BSC_URL', ''),
            'orgao' => self::strEnv('AVALIACAO_ORGAO'),

            'servicos' => [
                'cadastro' => self::strEnv('AVALIACAO_SERVICO_CADASTRO'),
                'coletivo' => self::strEnv('AVALIACAO_SERVICO_COLETIVO'),
                'oportunidade' => self::strEnv('AVALIACAO_SERVICO_OPORTUNIDADE'),
                'evento' => self::strEnv('AVALIACAO_SERVICO_EVENTO'),
                'espaco' => self::strEnv('AVALIACAO_SERVICO_ESPACO'),
                'projeto' => self::strEnv('AVALIACAO_SERVICO_PROJETO'),
            ],

            // A autenticação no gateway é a mesma da consulta de CNPJ, então as
            // credenciais existentes são reaproveitadas. Se a COGOV confirmar
            // que /avaliacoes exige credencial própria, entram variáveis novas.
            'bscAuthUrl' => env('RCV_BSC_AUTH_TOKEN', ''),
            'bscClientId' => env('RCV_BSC_CLIENT_ID', ''),
            'bscClientSecret' => env('RCV_BSC_CLIENT_SECRET', ''),

            'metadataFieldCPF' => env('AUTH_METADATA_FIELD_DOCUMENT', 'documento'),

            // Metadado que o MultipleLocalAuth grava quando a pessoa confirma o
            // e-mail — ou quando prova posse dele recuperando a senha. É o sinal
            // de que o cadastro foi concluído.
            'accountActiveMetadata' => 'accountIsActive',

            // Apenas o Mapa da Cultura, por definição da área. O filtro é o id
            // do subsite, como fazem AldirBlanc e RCV — as duas integrações do
            // projeto restritas a um portal. O id muda por ambiente (1 em
            // desenvolvimento, 4 em homologação e produção), por isso vem do
            // .env; sem ele configurado, nada é registrado nem enviado.
            'subsiteId' => (int) env('AVALIACAO_SUBSITE_ID', 0),

            // Agentes individuais são o cadastro da própria pessoa, já coberto
            // pelo serviço "Cadastrar-se". Só os demais contam como coletivo.
            'agentTypeIndividual' => 1,

            'etapa' => 'Única',
            'situacaoEtapa' => '2', // Concluído
            'canalPrestacao' => '8', // Web
            'canalAvaliacao' => '1', // Formulário da plataforma
        ];

        parent::__construct($config);
    }

    /**
     * Registro das solicitações.
     *
     * Uma instância só, e não uma por chamada como o Security faz com
     * IpRegistry: marcar a entidade publicada e registrá-la acontecem em
     * ganchos diferentes, e a marcação precisa sobreviver entre os dois.
     */
    public function registry(): Services\SatisfactionRegistry
    {
        return $this->registry ??= new Services\SatisfactionRegistry($this);
    }

    /**
     * Envio de uma solicitação ao BSC, usado pelo job de varredura.
     */
    public function sender(): Services\SatisfactionSender
    {
        return $this->sender ??= new Services\SatisfactionSender($this);
    }

    function register()
    {
        $app = App::i();

        $app->registerJobType(new SendSatisfactionRequestJob(SendSatisfactionRequestJob::SLUG));
        $app->registerController('govbr-satisfaction-requests', Controllers\Requests::class);
    }

    function _init()
    {
        $app = App::i();

        if (!$this->config['enabled']) {
            return;
        }

        $plugin = $this;
        $entities = implode('|', array_keys(self::PUBLISHED_ENTITIES));

        // Publicação das cinco entidades. O gancho roda antes de `status`
        // receber o valor novo, então comparar com STATUS_ENABLED aqui diz se a
        // entidade já estava publicada — republicar não gera registro novo.
        $app->hook("entity(<<{$entities}>>).setStatus(" . Entity::STATUS_ENABLED . ')', function () use ($plugin) {
            /** @var Entity $this */
            if ($this->status == Entity::STATUS_ENABLED) {
                return;
            }

            $plugin->registry()->markForRegistration($this);
        });

        $app->hook("entity(<<{$entities}>>).save:finish", function () use ($plugin) {
            /** @var Entity $this */
            $plugin->registry()->registerMarked($this);
        });

        // "Criar e publicar" numa etapa, que a interface oferece em várias telas.
        // Agent, Event, Space e Project declaram STATUS_ENABLED como padrão, então
        // quem publica junto com a criação nunca passa por rascunho: o gancho
        // acima ou não dispara, ou dispara com a situação já publicada e a guarda
        // de republicação o descarta. Sem isto o caminho inteiro passa batido.
        //
        // `insert:finish` roda depois do flush e só para entidade nova, como o
        // save:finish — não é o insert:after, que roda dentro do commit do
        // Doctrine e faria o enfileiramento do job virar gravação aninhada.
        $app->hook("entity(<<{$entities}>>).insert:finish", function () use ($plugin) {
            /** @var Entity $this */
            if ($this->status != Entity::STATUS_ENABLED) {
                return;
            }

            $plugin->registry()->registerOnCreate($this);
        });

        // "Cadastrar-se" não passa por publicação, e conclui na confirmação do
        // e-mail, não na criação da conta: a pesquisa é entregue por e-mail,
        // até confirmar a conta não está ativa, e o agente que guarda o CPF
        // pode ainda não existir. Este gancho também roda depois do flush, e
        // não dentro do commit do Doctrine como o insert:after.
        $chaveConta = $this->config['accountActiveMetadata'];

        $app->hook('entity(UserMeta).save:finish', function () use ($plugin, $chaveConta) {
            /** @var \MapasCulturais\Entities\UserMeta $this */
            if ($this->key !== $chaveConta || (string) $this->value !== '1') {
                return;
            }

            $plugin->registry()->registerRequest($this->owner, 'cadastro', null);
        });

        $this->registerPanel($app);
    }

    /**
     * Acrescenta a página de consulta ao painel.
     *
     * Fica sob "Administração" e só para quem administra a instalação inteira:
     * a lista diz quem concluiu qual serviço e quando, e é a única fonte sobre
     * o que foi disparado ao gov.br — não há retorno de lá para conferir.
     *
     * A página é só leitura. As regras estão fechadas com a área e vivem no
     * código e no .env; uma tela para alterá-las seria um jeito de quebrar por
     * acidente o que foi acordado.
     */
    protected function registerPanel(App $app): void
    {
        if (!($app->view instanceof \MapasCulturais\Themes\BaseV2\Theme)) {
            return;
        }

        $plugin = $this;

        $app->hook('GET(panel.govbr-satisfaction)', function () use ($app, $plugin) {
            /** @var \MapasCulturais\Controllers\Panel $this */
            $this->requireAuthentication();

            if (!$app->user->is('saasSuperAdmin')) {
                $app->halt(403, i::__('Acesso restrito.'));
            }

            if (!$plugin->isPanelSubsite()) {
                $app->halt(404);
            }

            $app->view->enqueueStyle('app-v2', 'govbr-satisfaction', 'css/plugin-GovBrSatisfaction.css');

            $this->render('govbr-satisfaction');
        });

        $app->hook('panel.nav', function (&$nav) use ($app, $plugin) {
            if (!isset($nav['admin']) || !$plugin->isPanelSubsite()) {
                return;
            }

            $nav['admin']['items'][] = [
                'route' => 'panel/govbr-satisfaction',
                'icon' => 'govbr-satisfaction',
                'label' => i::__('Satisfação gov.br'),
                'condition' => fn() => $app->user->is('saasSuperAdmin'),
            ];
        });

        $app->hook('component(mc-icon).iconset', function (&$iconset) {
            $iconset['govbr-satisfaction'] = 'material-symbols:rate-review-outline';
        });
    }

    /**
     * Transporte conforme o ambiente: fixture não sai da máquina.
     *
     * Um Client posto em `config['client']` tem precedência. É a costura que
     * permite aos testes exercitar um transporte que falha — sem ela, o caso de
     * credencial recusada só apareceria contra o BSC de verdade.
     */
    public function client(): Client
    {
        if ($this->config['client'] instanceof Client) {
            return $this->config['client'];
        }

        if ($this->isDevMode()) {
            return new FixtureClient();
        }

        return new HttpClient(
            $this->config['bscUrl'],
            $this->config['bscAuthUrl'],
            $this->config['bscClientId'],
            $this->config['bscClientSecret']
        );
    }

    public function isDevMode(): bool
    {
        return (bool) $this->config['devMode'];
    }

    /**
     * Variáveis obrigatórias que estão em branco.
     *
     * Só importa quando o envio é real: em fixture o fluxo é exercitado com o
     * que houver, inclusive vazio, senão nenhuma solicitação sairia de
     * `pendente` na máquina de quem desenvolve.
     *
     * @return string[]
     */
    public function missingConfig(): array
    {
        $missing = [];

        if (!$this->config['bscUrl']) {
            $missing[] = 'AVALIACAO_BSC_URL';
        }

        if (!$this->config['orgao']) {
            $missing[] = 'AVALIACAO_ORGAO';
        }

        if ((int) $this->config['subsiteId'] < 1) {
            $missing[] = 'AVALIACAO_SUBSITE_ID';
        }

        // As credenciais são emprestadas do RCV e podem estar em branco numa
        // instalação que não usa aquele plugin. Sem elas o token não é obtido e
        // todo envio é descartado em silêncio, com o painel dizendo que está tudo
        // configurado.
        foreach (['bscAuthUrl' => 'RCV_BSC_AUTH_TOKEN', 'bscClientId' => 'RCV_BSC_CLIENT_ID', 'bscClientSecret' => 'RCV_BSC_CLIENT_SECRET'] as $chave => $variavel) {
            if (!$this->config[$chave]) {
                $missing[] = $variavel;
            }
        }

        foreach ($this->config['servicos'] as $key => $id) {
            if (!$id) {
                $missing[] = 'AVALIACAO_SERVICO_' . strtoupper($key);
            }
        }

        return $missing;
    }

    /**
     * O painel só existe no portal que o plugin atende.
     *
     * Checar o tema não distingue nada — os quatro herdam de BaseV2 —, e sem
     * isto o item apareceria no painel de Pnab, Funarte e CulturaViva, onde o
     * gatilho nunca dispara.
     */
    public function isPanelSubsite(): bool
    {
        return $this->subsiteRejectionReason(App::i()->getCurrentSubsite()) === null;
    }

    /**
     * Por que este subsite não pode ser atendido, ou null quando pode.
     *
     * Motivo em vez de booleano para que a recusa seja auditável, como em
     * AldirBlanc\Services\OpportunityService. Os três casos — sem subsite, sem
     * configuração e outro portal — falham fechado.
     */
    public function subsiteRejectionReason(?Subsite $subsite): ?string
    {
        $configured = (int) $this->config['subsiteId'];

        if ($configured < 1) {
            return 'AVALIACAO_SUBSITE_ID não configurado';
        }

        $subsiteId = (int) $subsite?->id;

        if ($subsiteId < 1) {
            return 'sem subsite';
        }

        if ($subsiteId !== $configured) {
            return "subsite {$subsiteId} não habilitado";
        }

        return null;
    }

    /**
     * Identificador do Portal de Serviços como texto.
     *
     * `env()` devolve os ids como float (`13687.0`), o que quebra duas vezes:
     * float não é chave de array, então array_flip zera os rótulos do painel, e
     * a API declara `servico` e `orgao` como texto.
     */
    private static function strEnv(string $name): string
    {
        $value = env($name, '');

        if ($value === null || $value === '') {
            return '';
        }

        return is_float($value) ? (string) (int) $value : (string) $value;
    }

    /**
     * `env()` devolve texto, e em PHP a string "false" é verdadeira — ler a
     * variável direto faria `AVALIACAO_DEV_MODE=FALSE` ligar o envio real ao
     * contrário do pretendido.
     */
    private static function boolEnv(string $name, bool $default): bool
    {
        $value = env($name, null);

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
