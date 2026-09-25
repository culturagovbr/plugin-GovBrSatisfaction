<?php

namespace Tests\GovBrSatisfaction;

use MapasCulturais\App;
use Tests\Traits\RequestFactory;

/** O que a lista do painel recebe. */
class ListingEndpointTest extends TestCase
{
    use RequestFactory;

    protected function registros(): array
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'index'), false);

        $this->assertSame(200, $app->response->getStatusCode());

        return json_decode((string) $app->response->getBody(), true)['registros'];
    }

    /** Sem nome no agente, a pessoa é o e-mail mascarado. */
    function testPessoaSemNomeMostraEmailMascarado()
    {
        $this->publicarEspaco();
        $this->conn()->executeStatement(
            "UPDATE agent SET name = '' WHERE id = ?",
            [$this->cidadao->profile->id]
        );

        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $pessoa = $this->registros()[0]['pessoa'];

        $this->assertStringNotContainsString($this->cidadao->email, $pessoa);
        $this->assertSame(\GovBrSatisfaction\Bsc\Mask::email($this->cidadao->email), $pessoa);
    }

    /** Resumo gravado por extenso sai mascarado. */
    function testDetalheGravadoPorExtensoSaiMascarado()
    {
        $this->publicarEspaco();
        $this->alterarLinha((int) $this->solicitacoes()[0]['id'], ['send_detail' => 'CPF 77689062768 inválido']);

        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertSame('CPF *** inválido', $this->registros()[0]['detalhe']);
    }
}
