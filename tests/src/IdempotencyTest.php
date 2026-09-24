<?php

namespace Tests\GovBrSatisfaction;

use Tests\Traits\SpaceDirector;

/**
 * Uma avaliação por serviço, por usuário, para sempre
 *
 * A garantia tem duas camadas: a consulta antes de gravar, que resolve o caso
 * comum de a pessoa concluir o mesmo serviço de novo, e o índice único do
 * banco, que é a palavra final.
 *
 * O que estes testes cobrem é a primeira camada — publicações em sequência, que
 * é o que acontece na prática. Concorrência real não é exercida aqui.
 */
class IdempotencyTest extends TestCase
{
    use SpaceDirector;

    function testSegundoEspacoDoMesmoUsuarioNaoRegistraDeNovo()
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

        $this->assertSame(1, $this->contar("servico = '{$this->servico('espaco')}'"));
    }

    function testRepublicarNaoRegistraDeNovo()
    {
        $espaco = $this->spaceDirector->createSpace($this->cidadao->profile);

        $this->publicar($espaco);
        $this->publicar($espaco);

        $this->assertSame(1, $this->contar());
    }

    /**
     * A regra é por serviço, não por pessoa: quem publica um espaço e um projeto
     * prestou dois serviços diferentes e avalia os dois.
     */
    function testServicosDiferentesRegistramSeparadamente()
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));
        $this->publicar($this->projeto());

        $this->assertSame(2, $this->contar());
    }

    function testUsuariosDiferentesRegistramSeparadamente()
    {
        $outro = $this->criarCidadao();

        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

        $this->login($outro);
        $this->publicar($this->spaceDirector->createSpace($outro->profile));

        $this->assertSame(2, $this->contar("servico = '{$this->servico('espaco')}'"));
    }

    protected function projeto()
    {
        $director = new \Tests\Directors\ProjectDirector;

        return $director->createProject($this->cidadao->profile);
    }
}
