<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Traits\AgentDirector;
use Tests\Traits\UserDirector;

/** Base dos testes do plugin. */
abstract class TestCase extends \Tests\Abstract\TestCase
{
    use AgentDirector;
    use UserDirector;

    /** CPF de exemplo da documentação da API. */
    const CPF = '77689062768';

    protected Subsite $subsite;
    protected Subsite $outroSubsite;

    /** Usuário com CPF, dono das entidades publicadas nos testes. */
    protected User $cidadao;

    private static ?array $configOriginal = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Restaura a configuração e recria os subsites.
        $this->restaurarConfiguracao();
        $this->configurar(['subsiteId' => 0]);

        $this->subsite = $this->criarSubsite('Portal atendido');
        $this->outroSubsite = $this->criarSubsite('Outro portal');

        $this->configurar(['subsiteId' => $this->subsite->id]);
        $this->noSubsite($this->subsite);

        $this->cidadao = $this->criarCidadao();

        $this->login($this->cidadao);

        $this->conn()->executeStatement('DELETE FROM govbr_satisfaction_request');
    }

    protected function conn()
    {
        return App::i()->em->getConnection();
    }

    protected function restaurarConfiguracao(): void
    {
        $prop = new \ReflectionProperty(\MapasCulturais\Module::class, '_config');
        $prop->setAccessible(true);

        if (self::$configOriginal === null) {
            self::$configOriginal = $prop->getValue($this->plugin());
        }

        $prop->setValue($this->plugin(), self::$configOriginal);
    }

    protected function plugin(): Plugin
    {
        return App::i()->plugins['GovBrSatisfaction'];
    }

    /** Sobrescreve a configuração do plugin em memória. */
    protected function configurar(array $valores): void
    {
        $plugin = $this->plugin();

        $prop = new \ReflectionProperty(\MapasCulturais\Module::class, '_config');
        $prop->setAccessible(true);

        $prop->setValue($plugin, array_replace_recursive($prop->getValue($plugin), $valores));
    }

    protected function noSubsite(Subsite $subsite): void
    {
        App::i()->setCurrentSubsiteId($subsite->id);
    }

    protected function criarSubsite(string $nome): Subsite
    {
        $app = App::i();
        $app->disableAccessControl();

        $sufixo = uniqid();

        $subsite = new Subsite;
        $subsite->name = "{$nome} {$sufixo}";
        $subsite->url = "{$sufixo}.teste";
        $subsite->namespace = 'Subsite';
        $subsite->owner = $this->agentDirector->createAgent($this->userDirector->createUser());
        $subsite->save(true);
        $app->em->flush();

        $app->enableAccessControl();

        $this->assertSame(
            1,
            (int) $this->conn()->fetchOne('SELECT count(*) FROM subsite WHERE id = ?', [$subsite->id]),
            'o subsite de teste não foi gravado'
        );

        return $subsite;
    }

    /** Usuário com CPF, sem e-mail confirmado. */
    protected function criarCidadao(): User
    {
        $app = App::i();
        $user = $this->userDirector->createUser();

        $app->disableAccessControl();
        $campo = $this->plugin()->config['metadataFieldCPF'];
        $user->profile->$campo = self::CPF;
        $user->profile->save(true);
        $app->em->flush();
        $app->enableAccessControl();

        return $user;
    }

    /** Grava o metadado de conta ativa. */
    protected function confirmarEmail(User $user): \MapasCulturais\Entities\UserMeta
    {
        $app = App::i();
        $app->disableAccessControl();

        $chave = $this->plugin()->config['accountActiveMetadata'];

        $meta = new \MapasCulturais\Entities\UserMeta;
        $meta->owner = $user;
        $meta->key = $chave;
        $meta->value = '1';
        $meta->save(true);

        $app->em->flush();
        $app->enableAccessControl();

        return $meta;
    }

    /** Agente do tipo pedido. */
    protected function criarAgente(int $tipo): \MapasCulturais\Entities\Agent
    {
        $app = App::i();

        $app->disableAccessControl();
        $agente = $this->agentDirector->createAgent($this->cidadao, $tipo);
        $app->em->flush();
        $app->enableAccessControl();

        return $agente;
    }

    /** O cenário mais comum da suíte. */
    protected function publicarEspaco(): void
    {
        $this->publicar($this->spaceDirector()->createSpace($this->cidadao->profile));
    }

    protected function spaceDirector(): \Tests\Directors\SpaceDirector
    {
        return new \Tests\Directors\SpaceDirector;
    }

    /** Agente do cidadão relido do banco. */
    protected function perfilAtual(): \MapasCulturais\Entities\Agent
    {
        return App::i()->repo('Agent')->find($this->cidadao->profile->id);
    }

    /** UPDATE direto na linha, com o EntityManager limpo. */
    protected function alterarLinha(int $id, array $colunas): void
    {
        $sets = implode(', ', array_map(fn($c) => "{$c} = ?", array_keys($colunas)));

        $this->conn()->executeStatement(
            "UPDATE govbr_satisfaction_request SET {$sets} WHERE id = ?",
            [...array_values($colunas), $id]
        );

        App::i()->em->clear();
    }

    /** Transporte que devolve sempre o mesmo desfecho e conta as chamadas. */
    protected function clienteQueDevolve(\GovBrSatisfaction\Bsc\Result $resultado): \GovBrSatisfaction\Bsc\Client
    {
        return new class($resultado) implements \GovBrSatisfaction\Bsc\Client {
            public int $chamadas = 0;

            public function __construct(private \GovBrSatisfaction\Bsc\Result $resultado) {}

            public function send(array $payload): \GovBrSatisfaction\Bsc\Result
            {
                $this->chamadas++;

                return $this->resultado;
            }
        };
    }

    /** Transporte que decide pelo payload: `$decide(array $payload): Result`. */
    protected function clienteQueDecide(callable $decide): \GovBrSatisfaction\Bsc\Client
    {
        return new class($decide) implements \GovBrSatisfaction\Bsc\Client {
            public function __construct(private $decide) {}

            public function send(array $payload): \GovBrSatisfaction\Bsc\Result
            {
                return ($this->decide)($payload);
            }
        };
    }

    protected function clienteQueLanca(\Throwable $e): \GovBrSatisfaction\Bsc\Client
    {
        return $this->clienteQueDecide(function () use ($e) {
            throw $e;
        });
    }

    protected function solicitacoes(string $where = '1=1'): array
    {
        return $this->conn()->fetchAllAssociative(
            "SELECT * FROM govbr_satisfaction_request WHERE {$where} ORDER BY id"
        );
    }

    protected function contar(string $where = '1=1'): int
    {
        return (int) $this->conn()->fetchOne(
            "SELECT count(*) FROM govbr_satisfaction_request WHERE {$where}"
        );
    }

    /** Cria já publicada. */
    protected function criarPublicado($entidade): void
    {
        $app = App::i();

        $this->login($entidade->ownerUser);

        $app->disableAccessControl();

        $entidade->status = \MapasCulturais\Entity::STATUS_ENABLED;
        $entidade->save(true);

        $app->em->flush();
        $app->enableAccessControl();
    }

    /** Rascunho e depois publicada, como o dono. */
    protected function publicar($entidade): void
    {
        $app = App::i();

        $this->login($entidade->ownerUser);

        $app->disableAccessControl();

        $entidade->status = \MapasCulturais\Entity::STATUS_DRAFT;
        $entidade->save(true);

        $entidade->status = \MapasCulturais\Entity::STATUS_ENABLED;
        $entidade->save(true);

        $app->em->flush();
        $app->enableAccessControl();
    }

    /** Executa os jobs existentes, um por vez, sem subsite. */
    protected function processarEnvios(): void
    {
        $app = App::i();

        $app->em->flush();

        $existentes = (int) $this->conn()->fetchOne('SELECT count(*) FROM job');

        if ($existentes === 0) {
            return;
        }

        for ($i = 0; $i < $existentes; $i++) {
            $this->conn()->executeStatement('UPDATE job SET subsite_id = NULL');

            $app->em->clear();

            $this->processJobs(number_of_jobs: 1);
        }
    }

    protected function assertSituacao(string $esperada, array $linha, string $mensagem = ''): void
    {
        $this->assertSame($esperada, $linha['send_status'], $mensagem);
    }

    protected function servico(string $chave): string
    {
        return (string) $this->plugin()->config['servicos'][$chave];
    }
}
