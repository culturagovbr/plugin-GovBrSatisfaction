<?php

namespace Tests\GovBrSatisfaction;

use MapasCulturais\App;
use Tests\Traits\RequestFactory;

/** Acesso à página e aos endpoints do painel. */
class PanelAccessTest extends TestCase
{
    use RequestFactory;

    protected function abrir(string $acao = 'govbr-satisfaction'): int
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->GET('panel', $acao), false);

        return $app->response->getStatusCode();
    }

    function testVisitanteNaoEntra()
    {
        $this->logout();

        $this->assertSame(401, $this->abrir());
    }

    function testUsuarioComumNaoEntra()
    {
        $this->login($this->userDirector->createUser());

        $this->assertSame(403, $this->abrir());
    }

    function testAdministradorDaInstalacaoEntra()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertSame(200, $this->abrir());
    }

    function testNaoExisteEmOutroPortal()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $this->noSubsite($this->outroSubsite);

        $this->assertSame(404, $this->abrir());
    }

    protected function consultar(string $acao, array $params = []): int
    {
        $app = App::i();
        $app->reset();
        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', $acao, [], $params), false);

        return $app->response->getStatusCode();
    }

    /**
     * @dataProvider endpointsDeLeitura
     */
    function testConsultaExigeAdministradorDaInstalacao(string $acao)
    {
        $this->login($this->userDirector->createUser());

        $this->assertSame(403, $this->consultar($acao, ['id' => 1]));
    }

    public static function endpointsDeLeitura(): array
    {
        return [
            'index' => ['index'],
            'payload' => ['payload'],
            'dispatches' => ['dispatches'],
            'status' => ['status'],
        ];
    }

    function testAdministradorConsulta()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertSame(200, $this->consultar('index'));
        $this->assertSame(200, $this->consultar('status'));
    }

    /** Os endpoints seguem a página. */
    function testEndpointsNaoExistemEmOutroPortal()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $this->noSubsite($this->outroSubsite);

        $this->assertSame(404, $this->consultar('index'));
        $this->assertSame(404, $this->consultar('dispatches', ['id' => 1]));
    }

    function testPluginDesligadoResponde503()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $this->configurar(['enabled' => false]);

        $this->assertSame(503, $this->consultar('index'));
    }

    /** POST de formulário comum, sem o cabeçalho que a tela envia. */
    protected function escrever(string $acao, array $dados, array $cabecalhos = []): int
    {
        $app = App::i();
        $app->reset();
        $app->run($this->requestFactory->POST('govbr-satisfaction-requests', $acao, [], $dados, headers: $cabecalhos, ajax: false), false);

        return $app->response->getStatusCode();
    }

    /**
     * Escrita que não vem da tela é recusada.
     *
     * @dataProvider endpointsDeEscrita
     */
    function testEscritaForaDaTelaEhRecusada(string $acao)
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertSame(400, $this->escrever($acao, ['id' => 1, 'ids' => [1], 'tentativa' => 1, 'motivo' => 'motivo de teste']));
    }

    public static function endpointsDeEscrita(): array
    {
        return [
            'requeue' => ['requeue'],
            'requeueAll' => ['requeueAll'],
            'requeueSelected' => ['requeueSelected'],
            'unlockReveal' => ['unlockReveal'],
            'reveal' => ['reveal'],
        ];
    }

    /** JSON com charset é pedido da tela. */
    function testEscritaEmJsonComCharsetPassa()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertSame(404, $this->escrever('requeue', ['id' => 999999999], ['Content-Type' => 'application/json; charset=utf-8']));
    }
}
