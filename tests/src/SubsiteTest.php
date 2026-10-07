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

    /** Linha de outro portal fica recusada com o motivo, sem envio. */
    function testEnvioRecusaLinhaDeOutroPortal()
    {
        $this->publicarEspaco();

        $this->conn()->executeStatement(
            'UPDATE govbr_satisfaction_request SET subsite_id = ?, send_status = ?',
            [$this->outroSubsite->id, 'pendente']
        );

        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];
        $this->assertSituacao('recusado', $linha);
        $this->assertStringContainsString("subsite {$this->outroSubsite->id} não habilitado", $linha['send_detail']);
        $this->assertSame([], $this->tentativas());
    }

    /** Subsite zerado por engano não apaga as pendentes. */
    function testSubsiteZeradoNaoApagaAsPendentes()
    {
        $this->publicarEspaco();
        $this->configurar(['subsiteId' => 0]);

        $this->processarEnvios();

        $this->assertSame(1, $this->contar());
        $this->assertSituacao('recusado', $this->solicitacoes()[0]);
        $this->assertStringContainsString('AVALIACAO_SUBSITE_ID não configurado', $this->solicitacoes()[0]['send_detail']);
    }
}
