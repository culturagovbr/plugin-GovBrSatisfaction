<?php

namespace Tests\GovBrSatisfaction;

use Tests\Traits\SpaceDirector;

/**
 * Configuração incompleta
 *
 * O plugin só opera inteiro: faltando variável obrigatória nada é registrado,
 * em vez de acumular fila esperando alguém arrumar o `.env`.
 *
 * Vale só no modo real — em desenvolvimento o fluxo roda com o que houver.
 */
class ConfigurationTest extends TestCase
{
    use SpaceDirector;

    protected function publicarNoModoReal(array $configuracao): void
    {
        $this->configurar($configuracao + ['devMode' => false]);

        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));
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

    function testComTudoConfiguradoRegistra()
    {
        $this->publicarNoModoReal([]);

        $this->assertSame(1, $this->contar());
    }

    /**
     * Em desenvolvimento a configuração pode estar vazia: nada sai da máquina,
     * e exigir os valores impediria de exercitar o fluxo.
     */
    function testEmDesenvolvimentoAConfiguracaoVaziaNaoImpede()
    {
        $this->configurar(['orgao' => '', 'devMode' => true]);

        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

        $this->assertSame(1, $this->contar());
    }

    function testMissingConfigApontaAVariavelQueFalta()
    {
        $this->configurar(['orgao' => '']);

        $this->assertContains('AVALIACAO_ORGAO', $this->plugin()->missingConfig());
    }
}
