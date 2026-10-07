<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Services\PayloadReveal;
use GovBrSatisfaction\Services\RevealAudit;
use MapasCulturais\App;
use MapasCulturais\Entities\User;
use Tests\Traits\RequestFactory;

/** Revelar o payload real, com senha, janela e registro. */
class RevealEndpointTest extends TestCase
{
    use RequestFactory;

    const MOTIVO = 'Cidadão contestou o envio no chamado 123';

    const SENHA = 'senha-do-admin-123';

    /** Registro em memória no lugar do MapasBlame. */
    protected RevealAudit $registro;

    protected function setUp(): void
    {
        parent::setUp();

        unset($_SESSION[PayloadReveal::SESSION_KEY]);

        $this->registro = new class implements RevealAudit {
            public array $entradas = [];

            public function record(string $action, array $data): void
            {
                $this->entradas[] = ['acao' => $action] + $data;
            }
        };

        $this->configurar([
            'payloadKeys' => '1:' . base64_encode(random_bytes(32)),
            'revealUsers' => [],
            'revealAudit' => $this->registro,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_SESSION[PayloadReveal::SESSION_KEY]);

        parent::tearDown();
    }

    /** Envio feito com o cofre ligado; devolve o id da tentativa. */
    protected function tentativaCifrada(): int
    {
        $this->publicarEspaco();
        $this->processarEnvios();

        return (int) $this->ultimaTentativa()['id'];
    }

    /** Grava a senha local do usuário. */
    protected function comSenha(User $user): User
    {
        $app = App::i();
        $app->disableAccessControl();
        $user->setMetadata(PayloadReveal::PASSWORD_META, password_hash(self::SENHA, PASSWORD_DEFAULT));
        $user->save(true);
        $app->enableAccessControl();

        return $user;
    }

    protected function autorizado(): User
    {
        $admin = $this->comSenha($this->userDirector->createUser('saasSuperAdmin'));
        $lista = $this->plugin()->config['revealUsers'];
        $this->configurar(['revealUsers' => [...(array) $lista, $admin->id]]);
        $this->login($admin);

        return $admin;
    }

    protected function post(string $acao, array $dados): array
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->POST('govbr-satisfaction-requests', $acao, [], $dados), false);

        return [
            $app->response->getStatusCode(),
            json_decode((string) $app->response->getBody(), true),
            $app->response->getHeaderLine('Cache-Control'),
        ];
    }

    protected function auditoria(): array
    {
        return $this->registro->entradas;
    }

    /** Ação e razão de cada recusa registrada. */
    protected function recusas(): array
    {
        $negados = array_filter($this->auditoria(), fn($linha) => $linha['acao'] === 'negado');

        return array_values(array_map(fn($linha) => [$linha['acao'], $linha['razao']], $negados));
    }

    function testRevelaDentroDaJanelaERegistraComOMotivo()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        [$status, $janela, $cache] = $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);

        $this->assertSame(200, $status);
        $this->assertSame('no-store', $cache);
        $this->assertSame(PayloadReveal::WINDOW, $janela['segundos']);
        $this->assertEqualsWithDelta(time() + PayloadReveal::WINDOW, $janela['ate'], 2);

        [$status, $dados, $cache] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(200, $status);
        $this->assertSame('no-store', $cache);
        $this->assertEqualsWithDelta(PayloadReveal::WINDOW, $dados['segundos'], 2);
        $this->assertSame(self::CPF, $dados['payload']['cpfCidadao']);
        $this->assertSame($this->cidadao->email, $dados['payload']['email']);

        $this->assertSame(
            [
                ['acao' => 'liberar', 'motivo' => self::MOTIVO],
                ['acao' => 'revelar', 'envio' => $this->envios()[0]['uuid'], 'tentativa' => 1, 'motivo' => self::MOTIVO],
            ],
            $this->auditoria()
        );

        $registro = json_encode($this->auditoria());
        $this->assertStringNotContainsString(self::CPF, $registro);
        $this->assertStringNotContainsString($this->cidadao->email, $registro);
    }

    function testCopiarFicaRegistradoComoCopia()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa, 'acao' => 'copiar']);

        $this->assertSame(200, $status, json_encode($dados));
        $auditoria = $this->auditoria();
        $this->assertSame('copiar', end($auditoria)['acao']);
    }

    /** Acima do limite da janela, recusa e só volta com um novo motivo. */
    function testLimiteDaJanelaPedeNovoMotivo()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        [, $janela] = $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);
        $this->assertSame(PayloadReveal::WINDOW_LIMIT, $janela['restantes']);

        for ($i = 1; $i <= PayloadReveal::WINDOW_LIMIT; $i++) {
            [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa, 'acao' => $i % 2 ? 'revelar' : 'copiar']);

            $this->assertSame(200, $status);
            $this->assertSame(PayloadReveal::WINDOW_LIMIT - $i, $dados['restantes']);
        }

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(403, $status);
        $this->assertFalse($dados['janela']);
        $this->assertTrue($dados['limite']);
        $this->assertArrayNotHasKey('payload', $dados);

        $this->assertSame([['negado', 'limite da janela']], $this->recusas());

        $this->post('unlockReveal', ['motivo' => 'Segundo chamado aberto pelo cidadão', 'senha' => self::SENHA]);
        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(200, $status);
    }

    function testSemJanelaRecusaERegistra()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(403, $status);
        $this->assertFalse($dados['janela']);
        $this->assertArrayNotHasKey('payload', $dados);

        $this->assertSame(
            [['acao' => 'negado', 'envio' => $this->envios()[0]['uuid'], 'tentativa' => 1, 'razao' => 'sem janela aberta']],
            $this->auditoria()
        );
    }

    function testJanelaVencidaFecha()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);

        $_SESSION[PayloadReveal::SESSION_KEY]['until'] = time() - 1;

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(403, $status);
        $this->assertFalse($dados['janela']);
    }

    /** A janela é de quem informou o motivo. */
    function testJanelaNaoPassaParaOutroUsuario()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);

        $this->autorizado();
        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(403, $status);
    }

    function testForaDaListaNaoAbreJanelaNemRevela()
    {
        $tentativa = $this->tentativaCifrada();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status] = $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);
        $this->assertSame(403, $status);

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);
        $this->assertSame(403, $status);
        $this->assertArrayNotHasKey('payload', $dados);

        $this->assertSame(['negado', 'negado'], array_column($this->auditoria(), 'acao'));
    }

    /** Fora da lista, a resposta não diz se a tentativa existe. */
    function testForaDaListaNaoDistingueTentativaInexistente()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status] = $this->post('reveal', ['tentativa' => 999999999]);

        $this->assertSame(403, $status);
    }

    function testUsuarioComumNaoChegaAoEndpoint()
    {
        $tentativa = $this->tentativaCifrada();
        $this->login($this->userDirector->createUser());

        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(403, $status);
    }

    function testMotivoCurtoDa400()
    {
        $this->autorizado();

        [$status, $dados] = $this->post('unlockReveal', ['motivo' => '  curto  ', 'senha' => self::SENHA]);

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $dados);
        $this->assertNull($_SESSION[PayloadReveal::SESSION_KEY] ?? null);
    }

    function testSenhaErradaNaoAbreJanelaERegistra()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        [$status, $dados] = $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => 'outra-senha']);

        $this->assertSame(403, $status);
        $this->assertSame('Senha incorreta.', $dados['error']);
        $this->assertFalse($dados['senha']);
        $this->assertNull($_SESSION[PayloadReveal::SESSION_KEY] ?? null);
        $this->assertSame([['negado', 'senha incorreta']], $this->recusas());

        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(403, $status);
    }

    function testSenhaVaziaNaoAbreJanela()
    {
        $this->autorizado();

        [$status, $dados] = $this->post('unlockReveal', ['motivo' => self::MOTIVO]);

        $this->assertSame(403, $status);
        $this->assertFalse($dados['senha']);
        $this->assertNull($_SESSION[PayloadReveal::SESSION_KEY] ?? null);
    }

    function testContaSemSenhaLocalNaoAbreJanela()
    {
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->configurar(['revealUsers' => [$admin->id]]);
        $this->login($admin);

        [$status, $dados] = $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);

        $this->assertSame(403, $status);
        $this->assertStringContainsString('não tem senha cadastrada', $dados['error']);
        $this->assertNull($_SESSION[PayloadReveal::SESSION_KEY] ?? null);
        $this->assertSame([['negado', 'conta sem senha local']], $this->recusas());
    }

    /** A senha não vai para o registro. */
    function testSenhaNaoFicaRegistrada()
    {
        $this->autorizado();

        $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => 'outra-senha']);
        $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);

        $auditoria = json_encode($this->auditoria());

        $this->assertStringNotContainsString(self::SENHA, $auditoria);
        $this->assertStringNotContainsString('outra-senha', $auditoria);
    }

    /** Falha no registro não impede a revelação. */
    function testFalhaNoRegistroNaoDerrubaARevelacao()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        $this->configurar(['revealAudit' => new class implements RevealAudit {
            public function record(string $action, array $data): void
            {
                throw new \RuntimeException('banco do blame fora do ar');
            }
        }]);

        [$status] = $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);
        $this->assertSame(200, $status);

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);
        $this->assertSame(200, $status);
        $this->assertSame(self::CPF, $dados['payload']['cpfCidadao']);
    }

    /** Sem o MapasBlame ativo, o registro padrão não impede a revelação. */
    function testSemMapasBlameARevelacaoFunciona()
    {
        $this->assertArrayNotHasKey('MapasBlame', App::i()->plugins);

        $tentativa = $this->tentativaCifrada();
        $this->autorizado();
        $this->configurar(['revealAudit' => null]);

        $this->assertInstanceOf(\GovBrSatisfaction\Services\BlameRevealAudit::class, $this->plugin()->revealAudit());

        [$status] = $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);
        $this->assertSame(200, $status);

        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);
        $this->assertSame(200, $status);
    }

    function testTentativaSemConteudoGuardadoDa404()
    {
        $this->configurar(['payloadKeys' => '']);
        $tentativa = $this->tentativaCifrada();
        $this->configurar(['payloadKeys' => '1:' . base64_encode(random_bytes(32))]);

        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);

        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(404, $status);
    }

    /** Chave trocada sem manter a antiga: não abre, e não registra revelação. */
    function testChaveTrocadaNaoAbre()
    {
        $tentativa = $this->tentativaCifrada();
        $this->configurar(['payloadKeys' => '1:' . base64_encode(random_bytes(32))]);

        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO, 'senha' => self::SENHA]);

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(500, $status);
        $this->assertArrayNotHasKey('payload', $dados);
        $this->assertNotContains('revelar', array_column($this->auditoria(), 'acao'));
        $this->assertSame([['negado', 'não foi possível abrir o conteúdo guardado']], $this->recusas());
    }

    function testAcaoInvalidaDa400()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        [$status] = $this->post('reveal', ['tentativa' => $tentativa, 'acao' => 'apagar']);

        $this->assertSame(400, $status);
    }

    /** O status e o histórico dizem à tela quando mostrar o botão. */
    function testTelaSabeQuandoPodeRevelar()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        $app = App::i();
        $app->reset();
        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'status'), false);
        $status = json_decode((string) $app->response->getBody(), true);

        $this->assertSame(
            [
                'disponivel' => true,
                'autorizado' => true,
                'motivoMinimo' => PayloadReveal::REASON_MIN,
                'segundos' => PayloadReveal::WINDOW,
                'limite' => PayloadReveal::WINDOW_LIMIT,
            ],
            $status['revelacao']
        );

        $app->reset();
        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'dispatches', [], ['id' => (int) $this->solicitacoes()[0]['id']]), false);
        $envios = json_decode((string) $app->response->getBody(), true)['envios'];

        $this->assertSame($tentativa, $envios[0]['tentativas'][0]['id']);
        $this->assertTrue($envios[0]['tentativas'][0]['revelavel']);
    }
}
