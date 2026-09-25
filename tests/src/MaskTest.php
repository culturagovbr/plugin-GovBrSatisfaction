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
        'ipOrigem' => '200.130.5.7',
        'ipUsuario' => '2001:db8:85a3::8a2e:370:7334',
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
        $this->assertSame('200.130.***.***', $mascarado['ipOrigem']);
        $this->assertSame('2001:db8:***', $mascarado['ipUsuario']);
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
        $this->assertStringNotContainsString('200.130', json_encode($mascarado));
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
            'cpf com separadores misturados' => ['cpf 776890627-68 inválido', 'cpf *** inválido'],
            'cpf com espaços' => ['cpf 776 890 627 68', 'cpf ***'],
            'ipv4' => ['origem 200.130.5.7', 'origem ***'],
            'ipv6' => ['origem 2001:db8::1', 'origem ***'],
            'método e horário ficam' => ['erro em Foo::bar() às 10:11:07', 'erro em Foo::bar() às 10:11:07'],
            'número longo fica' => ['protocolo 20260925000123456', 'protocolo 20260925000123456'],
            'token bearer' => ['{"authorization":"Bearer abc.def-123"}', '{"authorization":"Bearer ***"}'],
            'bearer já mascarado' => ['Bearer ***', 'Bearer ***'],
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

    /**
     * Resposta do BSC mascarada.
     *
     * @dataProvider respostasDoBsc
     */
    function testRespostaDoBscSaiMascarada(string $corpo, string $esperado)
    {
        $this->assertSame($esperado, Mask::forBody($corpo));
    }

    public static function respostasDoBsc(): array
    {
        return [
            'campos pessoais aninhados' => [
                '{"message":"erro","recebido":{"cpfCidadao":"77689062768","email":"maria.silva@example.com","nomeCidadao":"Maria da Silva","ipUsuario":"200.130.5.7"}}',
                '{"message":"erro","recebido":{"cpfCidadao":"776.***.***-68","email":"m***@example.com","nomeCidadao":"Maria ***","ipUsuario":"200.130.***.***"}}',
            ],
            'cpf no texto' => ['{"detail":"CPF 77689062768 inválido"}', '{"detail":"CPF *** inválido"}'],
            'cpf numérico em campo pessoal' => ['{"cpfCidadao":77689062768}', '{"cpfCidadao":"776.***.***-68"}'],
            'lista' => ['[{"email":"ana@example.com"}]', '[{"email":"a***@example.com"}]'],
            'corpo que não é json' => ['erro para maria.silva@example.com', 'erro para ***'],
            'sem dado pessoal fica igual' => ['{"status": "BAD_REQUEST", "codigoErro": 1790278898}', '{"status": "BAD_REQUEST", "codigoErro": 1790278898}'],
            'cpf numérico fora de campo pessoal' => ['{"cpf":77689062768}', '{"cpf":"***"}'],
        ];
    }

    function testRespostaMascaradaDuasVezesDaNoMesmo()
    {
        $umaVez = Mask::forBody('{"recebido":{"cpfCidadao":"77689062768","email":"maria.silva@example.com"},"detail":"CPF 77689062768"}');

        $this->assertSame($umaVez, Mask::forBody($umaVez));
    }

    /** @dataProvider enderecos */
    function testIpMantemOsDoisPrimeirosBlocos(string $ip, string $esperado)
    {
        $this->assertSame($esperado, Mask::ip($ip));
        $this->assertSame($esperado, Mask::ip($esperado));
    }

    public static function enderecos(): array
    {
        return [
            'ipv4' => ['200.130.5.7', '200.130.***.***'],
            'ipv6' => ['2001:db8:85a3::8a2e:370:7334', '2001:db8:***'],
            'inválido' => ['desconhecido', '***'],
        ];
    }

    /** Os valores reais do envio saem onde aparecerem, com qualquer caixa. */
    function testValoresDoEnvioSaemDaResposta()
    {
        $corpo = '{"fieldErrors":[{"field":"nomeCidadao","rejectedValue":"Maria da Silva"},{"field":"ipOrigem","rejectedValue":"200.130.5.7"}],'
            . '"message":"maria da silva já avaliou"}';

        $this->assertSame(
            '{"fieldErrors":[{"field":"nomeCidadao","rejectedValue":"***"},{"field":"ipOrigem","rejectedValue":"***"}],"message":"*** já avaliou"}',
            Mask::forBody($corpo, Mask::personalValues(self::PAYLOAD))
        );
    }

    function testValoresPessoaisIgnoramVaziosECamposComuns()
    {
        $this->assertSame(['Ana'], Mask::personalValues(['nomeCidadao' => 'Ana', 'email' => '  ', 'servico' => '13683']));
    }

    /** Valor curto demais não é trocado no texto. */
    function testValorConhecidoCurtoFica()
    {
        $this->assertSame('Ana foi avisada', Mask::forLogText('Ana foi avisada', ['Ana']));
    }
}
