<?php

namespace Tests\GovBrSatisfaction;

use Tests\Traits\SpaceDirector;

/** Uma avaliação por serviço, por usuário. */
class IdempotencyTest extends TestCase
{
    use SpaceDirector;

    function testSegundoEspacoDoMesmoUsuarioNaoRegistraDeNovo()
    {
        $this->publicarEspaco();
        $this->publicarEspaco();

        $this->assertSame(1, $this->contar("servico = '{$this->servico('espaco')}'"));
    }

    function testRepublicarNaoRegistraDeNovo()
    {
        $espaco = $this->spaceDirector->createSpace($this->cidadao->profile);

        $this->publicar($espaco);
        $this->publicar($espaco);

        $this->assertSame(1, $this->contar());
    }

    /** Reativação e recuperação de senha gravam `accountIsActive = 1` de novo. */
    function testConfirmarEmailDeNovoNaoRegistraDeNovo()
    {
        $cidadao = $this->criarCidadao();
        $meta = $this->confirmarEmail($cidadao);

        $app = \MapasCulturais\App::i();
        $app->disableAccessControl();
        $meta->value = '1';
        $meta->save(true);
        $app->em->flush();
        $app->enableAccessControl();

        $this->assertSame(1, $this->contar("servico = '{$this->servico('cadastro')}'"));
    }

    function testServicosDiferentesRegistramSeparadamente()
    {
        $this->publicarEspaco();
        $this->publicar($this->projeto());

        $this->assertSame(2, $this->contar());
    }

    function testUsuariosDiferentesRegistramSeparadamente()
    {
        $outro = $this->criarCidadao();

        $this->publicarEspaco();

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
