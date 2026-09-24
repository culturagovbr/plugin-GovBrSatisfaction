<?php

namespace Tests\GovBrSatisfaction;

use Tests\Traits\SpaceDirector;

/**
 * O portal atendido
 *
 * Um subsite só. Publicação em qualquer outro é descartada, não marcada: não é
 * caso a acompanhar, é linha que não deveria existir.
 *
 * A barreira aparece duas vezes: a do gatilho evita gravar, e a do envio existe
 * porque é ali que dado de cidadão sai da plataforma.
 */
class SubsiteTest extends TestCase
{
    use SpaceDirector;

    function testPublicarNoPortalAtendidoRegistra()
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

        $this->assertSame(1, $this->contar());
    }

    function testPublicarEmOutroPortalNaoRegistra()
    {
        $this->noSubsite($this->outroSubsite);

        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

        $this->assertSame(0, $this->contar());
    }

    function testConfirmarEmailEmOutroPortalNaoRegistra()
    {
        $this->noSubsite($this->outroSubsite);

        $this->confirmarEmail($this->criarCidadao());

        $this->assertSame(0, $this->contar());
    }

    /**
     * Sem a variável preenchida o plugin não sabe que portal atende, e a falha
     * segura é não registrar — nunca registrar para todos.
     */
    function testSemSubsiteConfiguradoNaoRegistra()
    {
        $this->configurar(['subsiteId' => 0]);

        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

        $this->assertSame(0, $this->contar());
    }

    function testEnvioApagaLinhaDeOutroPortal()
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

        $this->conn()->executeStatement(
            'UPDATE govbr_satisfaction_request SET subsite_id = ?, send_status = ?',
            [$this->outroSubsite->id, 'pendente']
        );

        $this->processarEnvios();

        $this->assertSame(0, $this->contar(), 'a linha de outro portal deveria ter sido descartada');
    }
}
