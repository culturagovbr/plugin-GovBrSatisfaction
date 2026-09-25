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
 * Avaliação de satisfação dos serviços do Mapa da Cultura no gov.br: registra a
 * solicitação ao concluir um serviço e envia ao BSC por job.
 *
 * @package GovBrSatisfaction
 */
class Plugin extends \MapasCulturais\Plugin
{
    /** Papel que enxerga o painel e as solicitações. */
    const ADMIN_ROLE = 'saasSuperAdmin';

    private ?Services\SatisfactionRegistry $registry = null;

    private ?Services\SatisfactionSender $sender = null;

    /**
     * A instância registrada na aplicação, ou null se o plugin não carregou.
     */
    public static function instance(): ?self
    {
        $plugin = App::i()->plugins['GovBrSatisfaction'] ?? null;

        return $plugin instanceof self ? $plugin : null;
    }

    function __construct(array $config = [])
    {
        $services = [];

        foreach (Service::cases() as $service) {
            $services[$service->value] = self::strEnv($service->envVar());
        }

        $config += [
            'enabled' => true,

            // nulo: escolhe pelo ambiente
            'client' => null,

            // ausente = TRUE
            'devMode' => self::boolEnv('AVALIACAO_DEV_MODE', true),

            'bscUrl' => env('AVALIACAO_BSC_URL', ''),
            'orgao' => self::strEnv('AVALIACAO_ORGAO'),

            // id de cada serviço no Portal, pela chave do enum Service
            'servicos' => $services,

            // credenciais RCV_BSC_*
            'bscAuthUrl' => env('RCV_BSC_AUTH_TOKEN', ''),
            'bscClientId' => env('RCV_BSC_CLIENT_ID', ''),
            'bscClientSecret' => env('RCV_BSC_CLIENT_SECRET', ''),

            'metadataFieldCPF' => env('AUTH_METADATA_FIELD_DOCUMENT', 'documento'),

            // metadado gravado na confirmação do e-mail
            'accountActiveMetadata' => 'accountIsActive',

            // subsite atendido
            'subsiteId' => (int) env('AVALIACAO_SUBSITE_ID', 0),

            // tipo de agente que não conta como coletivo
            'agentTypeIndividual' => 1,

            // chaveiro do payload real cifrado: "1:base64,2:base64"; a maior versão cifra
            'payloadKeys' => env('AVALIACAO_CHAVES_PAYLOAD', ''),

            // ids dos usuários que podem revelar o payload real: "12,34"
            'revealUsers' => env('AVALIACAO_REVELAR_USUARIOS', ''),

            'etapa' => 'Única',
            'situacaoEtapa' => '2', // Concluído
            'canalPrestacao' => '8', // Web
            'canalAvaliacao' => '1', // Formulário da plataforma
        ];

        parent::__construct($config);
    }

    /** Registro; uma instância. */
    public function registry(): Services\SatisfactionRegistry
    {
        return $this->registry ??= new Services\SatisfactionRegistry($this);
    }

    /** Cofre do payload real; nulo sem chave válida. */
    public function vault(): ?Services\PayloadVault
    {
        return Services\PayloadVault::fromConfig((string) $this->config['payloadKeys']);
    }

    /** O usuário está na lista de quem pode revelar o payload real. */
    public function canReveal(?\MapasCulturais\Entities\User $user): bool
    {
        if (!$user || $user->is('guest') || !$user->is(self::ADMIN_ROLE)) {
            return false;
        }

        $allowed = $this->config['revealUsers'];
        $allowed = is_array($allowed) ? $allowed : explode(',', (string) $allowed);

        return in_array((int) $user->id, array_map('intval', $allowed), true);
    }

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
        $entities = implode('|', Service::entityTypes());

        // Publicação: transição para ENABLED.
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

        // Entidade que nasce publicada.
        $app->hook("entity(<<{$entities}>>).insert:finish", function () use ($plugin) {
            /** @var Entity $this */
            if ($this->status != Entity::STATUS_ENABLED) {
                return;
            }

            $plugin->registry()->registerOnCreate($this);
        });

        // Cadastrar-se: confirmação do e-mail.
        $activeKey = $this->config['accountActiveMetadata'];

        $app->hook('entity(UserMeta).save:finish', function () use ($plugin, $activeKey) {
            /** @var \MapasCulturais\Entities\UserMeta $this */
            if ($this->key !== $activeKey || (string) $this->value !== '1') {
                return;
            }

            if (!$this->owner instanceof \MapasCulturais\Entities\User) {
                return;
            }

            $plugin->registry()->registerRequest($this->owner, Service::Registration, null);
        });

        $this->registerPanel($app);
    }

    /** Página do painel. */
    protected function registerPanel(App $app): void
    {
        if (!($app->view instanceof \MapasCulturais\Themes\BaseV2\Theme)) {
            return;
        }

        $plugin = $this;

        $app->hook('GET(panel.govbr-satisfaction)', function () use ($app, $plugin) {
            /** @var \MapasCulturais\Controllers\Panel $this */
            $this->requireAuthentication();

            if (!$app->user->is(self::ADMIN_ROLE)) {
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
                'condition' => fn() => $app->user->is(self::ADMIN_ROLE),
            ];
        });

        $app->hook('component(mc-icon).iconset', function (&$iconset) {
            $iconset['govbr-satisfaction'] = 'material-symbols:rate-review-outline';
            $iconset['govbr-satisfaction-requeue'] = 'material-symbols:replay';
            $iconset['govbr-satisfaction-retry'] = 'material-symbols:send-outline';
            $iconset['govbr-satisfaction-history'] = 'material-symbols:history';
            $iconset['govbr-satisfaction-copy'] = 'material-symbols:content-copy-outline';
            $iconset['govbr-satisfaction-reveal'] = 'material-symbols:visibility-outline';
            $iconset['govbr-satisfaction-pending'] = 'material-symbols:schedule-outline';
            $iconset['govbr-satisfaction-success'] = 'material-symbols:check-circle';
            $iconset['govbr-satisfaction-simulated'] = 'material-symbols:code';
            $iconset['govbr-satisfaction-error'] = 'material-symbols:error';
            $iconset['govbr-satisfaction-rejected'] = 'material-symbols:cancel';
            $iconset['govbr-satisfaction-replaced'] = 'material-symbols:swap-horiz';
        });
    }

    /** Client configurado, fixture em dev, HTTP no modo real. */
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
     * Variáveis obrigatórias em branco. Só importa no modo real.
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

        foreach (['bscAuthUrl' => 'RCV_BSC_AUTH_TOKEN', 'bscClientId' => 'RCV_BSC_CLIENT_ID', 'bscClientSecret' => 'RCV_BSC_CLIENT_SECRET'] as $key => $variable) {
            if (!$this->config[$key]) {
                $missing[] = $variable;
            }
        }

        foreach (Service::cases() as $service) {
            if (!($this->config['servicos'][$service->value] ?? '')) {
                $missing[] = $service->envVar();
            }
        }

        return $missing;
    }

    public function isPanelSubsite(): bool
    {
        return $this->subsiteRejectionReason(App::i()->getCurrentSubsite()) === null;
    }

    /** Motivo da recusa do subsite, ou null. */
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

    /** Lê a variável como texto. */
    private static function strEnv(string $name): string
    {
        $value = env($name, '');

        if ($value === null || $value === '') {
            return '';
        }

        return is_float($value) ? (string) (int) $value : (string) $value;
    }

    /** Lê a variável como booleano. */
    private static function boolEnv(string $name, bool $default): bool
    {
        $value = env($name, null);

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
