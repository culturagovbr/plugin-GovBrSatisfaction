<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Services\PayloadReveal;
use MapasCulturais\App;
use MapasCulturais\Entities\User;
use Tests\Traits\RequestFactory;

/** Revelar o payload real, com janela e auditoria. */
class RevealEndpointTest extends TestCase
{
    use RequestFactory;

    const MOTIVO = 'Cidadão contestou o envio no chamado 123';

    protected function setUp(): void
    {
        parent::setUp();

        unset($_SESSION[PayloadReveal::SESSION_KEY]);
        $this->conn()->executeStatement('DELETE FROM govbr_satisfaction_reveal');
        $this->configurar(['payloadKeys' => '1:' . base64_encode(random_bytes(32)), 'revealUsers' => []]);
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

    protected function autorizado(): User
    {
        $admin = $this->userDirector->createUser('saasSuperAdmin');
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
        return $this->conn()->fetchAllAssociative('SELECT * FROM govbr_satisfaction_reveal ORDER BY id');
    }

    function testRevelaDentroDaJanelaEAuditaComOMotivo()
    {
        $tentativa = $this->tentativaCifrada();
        $admin = $this->autorizado();

        [$status, $janela, $cache] = $this->post('unlockReveal', ['motivo' => self::MOTIVO]);

        $this->assertSame(200, $status);
        $this->assertSame('no-store', $cache);
        $this->assertSame(PayloadReveal::WINDOW, $janela['segundos']);
        $this->assertEqualsWithDelta(time() + PayloadReveal::WINDOW, $janela['ate'], 2);

        [$status, $dados, $cache] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(200, $status);
        $this->assertSame('no-store', $cache);
        $this->assertSame(self::CPF, $dados['payload']['cpfCidadao']);
        $this->assertSame($this->cidadao->email, $dados['payload']['email']);

        [$liberar, $revelar] = $this->auditoria();

        $this->assertSame(['liberar', self::MOTIVO, null], [$liberar['action'], $liberar['reason'], $liberar['attempt_id']]);
        $this->assertSame(['revelar', self::MOTIVO, $tentativa], [$revelar['action'], $revelar['reason'], (int) $revelar['attempt_id']]);
        $this->assertSame((int) $this->solicitacoes()[0]['id'], (int) $revelar['request_id']);
        $this->assertSame([$admin->id, $admin->id], array_map('intval', array_column($this->auditoria(), 'user_id')));
        $this->assertStringNotContainsString(self::CPF, json_encode($this->auditoria()));
    }

    function testCopiarFicaRegistradoComoCopia()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO]);

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa, 'acao' => 'copiar']);

        $this->assertSame(200, $status, json_encode($dados));
        $auditoria = $this->auditoria();
        $this->assertSame('copiar', end($auditoria)['action']);
    }

    /** Acima do limite da janela, recusa e só volta com um novo motivo. */
    function testLimiteDaJanelaPedeNovoMotivo()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        [, $janela] = $this->post('unlockReveal', ['motivo' => self::MOTIVO]);
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

        $auditoria = $this->auditoria();
        $this->assertSame(['negado', 'limite da janela'], [end($auditoria)['action'], end($auditoria)['reason']]);

        $this->post('unlockReveal', ['motivo' => 'Segundo chamado aberto pelo cidadão']);
        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(200, $status);
    }

    function testSemJanelaRecusaEAudita()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(403, $status);
        $this->assertFalse($dados['janela']);
        $this->assertArrayNotHasKey('payload', $dados);

        $negado = $this->auditoria()[0];
        $this->assertSame(['negado', $tentativa], [$negado['action'], (int) $negado['attempt_id']]);
    }

    function testJanelaVencidaFecha()
    {
        $tentativa = $this->tentativaCifrada();
        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO]);

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
        $this->post('unlockReveal', ['motivo' => self::MOTIVO]);

        $this->autorizado();
        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(403, $status);
    }

    function testForaDaListaNaoAbreJanelaNemRevela()
    {
        $tentativa = $this->tentativaCifrada();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status] = $this->post('unlockReveal', ['motivo' => self::MOTIVO]);
        $this->assertSame(403, $status);

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);
        $this->assertSame(403, $status);
        $this->assertArrayNotHasKey('payload', $dados);

        $this->assertSame(['negado', 'negado'], array_column($this->auditoria(), 'action'));
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

        [$status, $dados] = $this->post('unlockReveal', ['motivo' => '  curto  ']);

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $dados);
        $this->assertNull($_SESSION[PayloadReveal::SESSION_KEY] ?? null);
    }

    function testTentativaSemConteudoGuardadoDa404()
    {
        $this->configurar(['payloadKeys' => '']);
        $tentativa = $this->tentativaCifrada();
        $this->configurar(['payloadKeys' => '1:' . base64_encode(random_bytes(32))]);

        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO]);

        [$status] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(404, $status);
    }

    /** Chave trocada sem manter a antiga: não abre, e não registra revelação. */
    function testChaveTrocadaNaoAbre()
    {
        $tentativa = $this->tentativaCifrada();
        $this->configurar(['payloadKeys' => '1:' . base64_encode(random_bytes(32))]);

        $this->autorizado();
        $this->post('unlockReveal', ['motivo' => self::MOTIVO]);

        [$status, $dados] = $this->post('reveal', ['tentativa' => $tentativa]);

        $this->assertSame(500, $status);
        $this->assertArrayNotHasKey('payload', $dados);
        $this->assertNotContains('revelar', array_column($this->auditoria(), 'action'));
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
