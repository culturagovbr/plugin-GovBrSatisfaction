<?php

namespace Tests\GovBrSatisfaction;

use Tests\Traits\SpaceDirector;

/** Um portal só. */
class SubsiteTest extends TestCase
{
    use SpaceDirector;

    function testPublicarNoPortalAtendidoRegistra()
    {
        $this->publicarEspaco();

        $this->assertSame(1, $this->contar());
    }

    function testPublicarEmOutroPortalNaoRegistra()
    {
        $this->noSubsite($this->outroSubsite);

        $this->publicarEspaco();

        $this->assertSame(0, $this->contar());
    }

    function testConfirmarEmailEmOutroPortalNaoRegistra()
    {
        $this->noSubsite($this->outroSubsite);

        $this->confirmarEmail($this->criarCidadao());

        $this->assertSame(0, $this->contar());
    }

    function testSemSubsiteConfiguradoNaoRegistra()
    {
        $this->configurar(['subsiteId' => 0]);

        $this->publicarEspaco();

        $this->assertSame(0, $this->contar());
    }

    function testEnvioApagaLinhaDeOutroPortal()
    {
        $this->publicarEspaco();

        $this->conn()->executeStatement(
            'UPDATE govbr_satisfaction_request SET subsite_id = ?, send_status = ?',
            [$this->outroSubsite->id, 'pendente']
        );

        $this->processarEnvios();

        $this->assertSame(0, $this->contar(), 'a linha de outro portal deveria ter sido descartada');
    }
}
