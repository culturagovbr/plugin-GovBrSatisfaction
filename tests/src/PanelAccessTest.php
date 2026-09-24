<?php

namespace Tests\GovBrSatisfaction;

use MapasCulturais\App;
use Tests\Traits\RequestFactory;

/**
 * Quem pode abrir a página do painel
 *
 * A lista diz quem concluiu qual serviço e quando, e é a única fonte sobre o que
 * foi disparado ao gov.br. Por isso é restrita a quem administra a instalação
 * inteira — e só existe no portal que o plugin atende, já que nos outros o
 * gatilho nunca dispara.
 */
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

        // 401, e não 403: quem não se identificou pode resolver entrando, e quem
        // se identificou e não tem o papel não. `assertNotSame(200)` aceitaria
        // 404 e 500 como se fossem o certo.
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

    /**
     * Fora do portal atendido a página não existe — e o item some do menu junto.
     * Só esconder do menu deixaria a tela acessível por URL.
     */
    function testNaoExisteEmOutroPortal()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $this->noSubsite($this->outroSubsite);

        $this->assertSame(404, $this->abrir());
    }

    function testConsultaExigeAdministradorDaInstalacao()
    {
        $this->login($this->userDirector->createUser());

        $app = App::i();
        $app->reset();
        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'index'), false);

        $this->assertSame(403, $app->response->getStatusCode());
    }
}
