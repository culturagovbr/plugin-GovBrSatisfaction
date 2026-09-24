<?php

namespace Tests\GovBrSatisfaction;

use MapasCulturais\App;
use Tests\Traits\RequestFactory;

/** Conteúdo e prévia do GET_payload. */
class PayloadEndpointTest extends TestCase
{
    use RequestFactory;

    protected function conteudo(int $id): array
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'payload', [], ['id' => $id]), false);

        $this->assertSame(200, $app->response->getStatusCode());

        return json_decode((string) $app->response->getBody(), true);
    }

    protected function idDaSolicitacao(): int
    {
        return (int) $this->solicitacoes()[0]['id'];
    }

    function testDepoisDoEnvioVemACopiaMascaradaEAResposta()
    {
        $this->publicarEspaco();
        $this->processarEnvios();

        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $dados = $this->conteudo($this->idDaSolicitacao());

        $this->assertFalse($dados['reconstruido']);
        $this->assertNull($dados['motivo']);
        $this->assertSame('776.***.***-68', $dados['payload']['cpfCidadao']);
        $this->assertSame($dados['payload']['cpfCidadao'], $dados['payload']['usuario']);
        $this->assertStringNotContainsString(self::CPF, json_encode($dados));

        // a resposta do BSC vem junto
        $this->assertStringContainsString('FIXTURE', (string) $dados['resposta']);
    }

    function testAntesDoEnvioVemAPreviaSinalizada()
    {
        $this->publicarEspaco();

        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $dados = $this->conteudo($this->idDaSolicitacao());

        $this->assertTrue($dados['reconstruido']);
        $this->assertNull($dados['motivo']);
        $this->assertSame($this->servico('espaco'), $dados['payload']['servico']);
        $this->assertStringNotContainsString(self::CPF, json_encode($dados));
        $this->assertNull($dados['resposta']);
    }

    function testSemCpfNaoHaConteudoEATelaSabePorQue()
    {
        $semCpf = $this->userDirector->createUser();
        $this->login($semCpf);
        $this->publicar($this->spaceDirector()->createSpace($semCpf->profile));

        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $dados = $this->conteudo($this->idDaSolicitacao());

        $this->assertNull($dados['payload']);
        $this->assertTrue($dados['reconstruido']);
        $this->assertNotEmpty($dados['motivo']);
    }

    function testSolicitacaoInexistenteDa404()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $app = App::i();
        $app->reset();
        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'payload', [], ['id' => 999999999]), false);

        $this->assertSame(404, $app->response->getStatusCode());
        $this->assertArrayHasKey('error', json_decode((string) $app->response->getBody(), true));
    }
}
