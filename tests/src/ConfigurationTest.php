<?php

namespace Tests\GovBrSatisfaction;

use Tests\Traits\SpaceDirector;

/** Configuração incompleta não registra (modo real). */
class ConfigurationTest extends TestCase
{
    use SpaceDirector;

    protected function publicarNoModoReal(array $configuracao): void
    {
        $this->configurar($configuracao + ['devMode' => false]);

        $this->publicarEspaco();
    }

    function testSemOrgaoNaoRegistra()
    {
        $this->publicarNoModoReal(['orgao' => '']);

        $this->assertSame(0, $this->contar());
    }

    function testSemIdDeServicoNaoRegistra()
    {
        $this->publicarNoModoReal(['servicos' => ['espaco' => '']]);

        $this->assertSame(0, $this->contar());
    }

    function testSemEnderecoDoBscNaoRegistra()
    {
        $this->publicarNoModoReal(['bscUrl' => '']);

        $this->assertSame(0, $this->contar());
    }

    /**
     * Credenciais RCV_BSC_* em branco.
     *
     * @dataProvider credenciaisDoGateway
     */
    function testSemCredencialDoGatewayNaoRegistra(string $chave, string $variavel)
    {
        $this->publicarNoModoReal([$chave => '']);

        $this->assertSame(0, $this->contar());
        $this->assertContains($variavel, $this->plugin()->missingConfig());
    }

    public static function credenciaisDoGateway(): array
    {
        return [
            'url do token' => ['bscAuthUrl', 'RCV_BSC_AUTH_TOKEN'],
            'client id' => ['bscClientId', 'RCV_BSC_CLIENT_ID'],
            'client secret' => ['bscClientSecret', 'RCV_BSC_CLIENT_SECRET'],
        ];
    }

    function testComTudoConfiguradoRegistra()
    {
        $this->publicarNoModoReal([]);

        $this->assertSame(1, $this->contar());
    }

    function testEmDesenvolvimentoAConfiguracaoVaziaNaoImpede()
    {
        $this->configurar(['orgao' => '', 'devMode' => true]);

        $this->publicarEspaco();

        $this->assertSame(1, $this->contar());
    }

    function testMissingConfigApontaAVariavelQueFalta()
    {
        $this->configurar(['orgao' => '']);

        $this->assertContains('AVALIACAO_ORGAO', $this->plugin()->missingConfig());
    }
}
