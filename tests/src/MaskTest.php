<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Mask;

/** Máscara de dado pessoal. */
class MaskTest extends TestCase
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
        $mascarado = Mask::forScreen(self::PAYLOAD);

        $this->assertSame('776.***.***-68', $mascarado['cpfCidadao']);
        $this->assertSame($mascarado['cpfCidadao'], $mascarado['cpfConsulta']);
        $this->assertSame($mascarado['cpfCidadao'], $mascarado['usuario']);
        $this->assertSame('m***@example.com', $mascarado['email']);
        $this->assertSame('Maria ***', $mascarado['nomeCidadao']);
        $this->assertSame('13683', $mascarado['servico'], 'campo não pessoal foi alterado');
    }

    function testParaLogCobreTudo()
    {
        $mascarado = Mask::forLog(self::PAYLOAD);

        foreach (Mask::PERSONAL_FIELDS as $campo) {
            $this->assertSame('***', $mascarado[$campo], "{$campo} vazou no log");
        }

        $this->assertSame('13683', $mascarado['servico']);
        $this->assertStringNotContainsString('77689062768', json_encode($mascarado));
        $this->assertStringNotContainsString('maria', json_encode($mascarado));
    }

    /**
     * Texto de log não carrega CPF nem e-mail.
     *
     * @dataProvider textosDeLog
     */
    function testTextoDeLogNaoCarregaCpfNemEmail(string $texto, string $esperado)
    {
        $this->assertSame($esperado, Mask::forLogText($texto));
    }

    public static function textosDeLog(): array
    {
        return [
            'cpf só dígitos' => ['cpfCidadao 77689062768 inválido', 'cpfCidadao *** inválido'],
            'cpf formatado' => ['CPF 776.890.627-68 não encontrado', 'CPF *** não encontrado'],
            'e-mail' => ['email maria.silva@example.com inválido', 'email *** inválido'],
            'dentro de json' => ['{"detail":"cpf 77689062768","email":"a@b.com"}', '{"detail":"cpf ***","email":"***"}'],
            'protocolo com cpf' => ['protocolo 77689062768ABC', 'protocolo ***ABC'],
            'sem dado pessoal' => ['no healthy upstream', 'no healthy upstream'],
            'número curto fica' => ['HTTP 500 codigoErro 1790278898', 'HTTP 500 codigoErro 1790278898'],
        ];
    }

    function testMascararDuasVezesDaNoMesmo()
    {
        $umaVez = Mask::forScreen(self::PAYLOAD);

        $this->assertSame($umaVez, Mask::forScreen($umaVez));
        $this->assertSame('776.***.***-68', $umaVez['cpfCidadao']);
    }

    function testCpfForaDoFormatoSaiTodoCoberto()
    {
        $this->assertSame('***', Mask::cpf('123'));
        $this->assertSame('***', Mask::cpf(''));
    }

    function testNomeDeUmaPalavraNaoGanhaAsteriscos()
    {
        $this->assertSame('Maria', Mask::name('Maria'));
        $this->assertSame('Maria ***', Mask::name('  Maria   da  Silva '));
    }

    function testEmailSemArroba()
    {
        $this->assertSame('m***', Mask::email('maria'));
    }
}
