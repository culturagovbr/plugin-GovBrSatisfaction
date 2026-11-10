<?php

namespace Tests\GovBrSatisfaction;

use MapasCulturais\App;
use Tests\Traits\RequestFactory;

/** O que a lista do painel recebe. */
class ListingEndpointTest extends TestCase
{
    use RequestFactory;

    protected function registros(array $params = []): array
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'index', [], $params), false);

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

    /** Duas solicitações de pessoas diferentes; devolve a do cidadão e a da outra. */
    protected function duasPessoas(): array
    {
        $this->publicarEspaco();

        $outra = $this->criarCidadao();
        $this->login($outra);
        $this->publicar($this->spaceDirector()->createSpace($outra->profile));

        $app = App::i();
        $app->disableAccessControl();
        $perfil = $app->repo('Agent')->find($this->cidadao->profile->id);
        $perfil->name = 'Maria Aparecida';
        $perfil->save(true);
        $app->em->flush();
        $app->enableAccessControl();

        return [$this->cidadao, $outra];
    }

    function testBuscaPeloIdDoUsuario()
    {
        [, $outra] = $this->duasPessoas();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $registros = $this->registros(['busca' => (string) $outra->id]);

        $this->assertSame([$outra->id], array_column($registros, 'userId'));
    }

    function testBuscaPeloNomeSemDiferenciarMaiusculas()
    {
        [$cidadao] = $this->duasPessoas();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $registros = $this->registros(['busca' => 'aparecida']);

        $this->assertSame([$cidadao->id], array_column($registros, 'userId'));
    }

    function testBuscaPeloUuidDoEnvio()
    {
        [, $outra] = $this->duasPessoas();
        $this->processarEnvios();

        $uuid = $this->conn()->fetchOne(
            'SELECT d.uuid FROM govbr_satisfaction_dispatch d JOIN govbr_satisfaction_request r ON r.id = d.request_id WHERE r.user_id = ?',
            [$outra->id]
        );

        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertSame([$outra->id], array_column($this->registros(['busca' => strtoupper($uuid)]), 'userId'));
    }

    function testBuscaSemResultadoOuComCuringa()
    {
        $this->duasPessoas();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->assertSame([], $this->registros(['busca' => 'ninguém com esse nome']));
        $this->assertSame([], $this->registros(['busca' => '%']));
    }
}
