<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Mascara;

/** Máscara de dado pessoal. */
class MascaraTest extends TestCase
{
    const PAYLOAD = [
        'cpfCidadao' => '77689062768',
        'cpfConsulta' => '77689062768',
        'usuario' => '77689062768',
        'email' => 'maria.silva@example.com',
        'nomeCidadao' => 'Maria da Silva',
        'servico' => '13683',
    ];

    /** Máscara parcial na tela. */
    function testParaTelaEhParcial()
    {
        $mascarado = Mascara::paraTela(self::PAYLOAD);

        $this->assertSame('776.***.***-68', $mascarado['cpfCidadao']);
        $this->assertSame($mascarado['cpfCidadao'], $mascarado['cpfConsulta']);
        $this->assertSame($mascarado['cpfCidadao'], $mascarado['usuario']);
        $this->assertSame('m***@example.com', $mascarado['email']);
        $this->assertSame('Maria ***', $mascarado['nomeCidadao']);
        $this->assertSame('13683', $mascarado['servico'], 'campo não pessoal foi alterado');
    }

    function testParaLogCobreTudo()
    {
        $mascarado = Mascara::paraLog(self::PAYLOAD);

        foreach (Mascara::CAMPOS_PESSOAIS as $campo) {
            $this->assertSame('***', $mascarado[$campo], "{$campo} vazou no log");
        }

        $this->assertSame('13683', $mascarado['servico']);
        $this->assertStringNotContainsString('77689062768', json_encode($mascarado));
        $this->assertStringNotContainsString('maria', json_encode($mascarado));
    }

    function testCpfForaDoFormatoSaiTodoCoberto()
    {
        $this->assertSame('***', Mascara::cpf('123'));
        $this->assertSame('***', Mascara::cpf(''));
    }

    function testNomeDeUmaPalavraNaoGanhaAsteriscos()
    {
        $this->assertSame('Maria', Mascara::nome('Maria'));
        $this->assertSame('Maria ***', Mascara::nome('  Maria   da  Silva '));
    }

    function testEmailSemArroba()
    {
        $this->assertSame('m***', Mascara::email('maria'));
    }
}
