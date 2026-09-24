<?php

namespace Tests\GovBrSatisfaction;

use MapasCulturais\App;
use Tests\Traits\RequestFactory;
use Tests\Traits\SpaceDirector;

/** Devolver à fila. */
class RequeueTest extends TestCase
{
    use RequestFactory;
    use SpaceDirector;

    /** Uma solicitação recusada, com o rastro de três tentativas. */
    protected function recusada(): int
    {
        $this->publicarEspaco();

        $id = (int) $this->solicitacoes()[0]['id'];

        $this->alterarLinha($id, [
            'send_status' => 'recusado',
            'send_attempts' => 3,
            'send_http_status' => 500,
            'send_detail' => 'Erro interno',
        ]);

        return $id;
    }

    protected function devolver(int $id): array
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->POST('govbr-satisfaction-requests', 'requeue', [], ['id' => $id]), false);

        return [
            $app->response->getStatusCode(),
            json_decode((string) $app->response->getBody(), true),
        ];
    }

    function testUsuarioComumNaoDevolve()
    {
        $id = $this->recusada();

        $this->login($this->userDirector->createUser());

        [$status] = $this->devolver($id);

        $this->assertSame(403, $status);
        $this->assertSituacao('recusado', $this->solicitacoes()[0]);
    }

    function testAdministradorDevolveEZeraAsTentativas()
    {
        $id = $this->recusada();

        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status, $corpo] = $this->devolver($id);

        $this->assertSame(200, $status);
        $this->assertSame('pendente', $corpo['situacao']);

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('pendente', $linha);
        $this->assertSame(0, (int) $linha['send_attempts'], 'o contador precisa zerar, senão a linha volta a ser recusada na primeira falha');
        $this->assertNull($linha['send_timestamp']);

        // o histórico fica, até a próxima tentativa sobrescrever
        $this->assertSame(500, (int) $linha['send_http_status']);
        $this->assertSame('Erro interno', $linha['send_detail']);
    }

    function testDepoisDeDevolverAVarreduraEnvia()
    {
        $id = $this->recusada();

        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $this->devolver($id);

        $this->processarEnvios();

        $this->assertSituacao('enviado', $this->solicitacoes()[0]);
    }

    /** Sem CPF também volta. */
    function testSemCpfVoltaAFilaEEnviaQuandoOCadastroGanhaCpf()
    {
        $semCpf = $this->userDirector->createUser();
        $this->login($semCpf);
        $this->publicar($this->spaceDirector->createSpace($semCpf->profile));
        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];
        $this->assertSituacao('sem-cpf', $linha);

        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        // ainda sem CPF: volta a sem-cpf
        [$status] = $this->devolver((int) $linha['id']);
        $this->assertSame(200, $status);
        $this->processarEnvios();
        $this->assertSituacao('sem-cpf', $this->solicitacoes()[0]);

        // cadastro completado
        $app = App::i();
        $perfil = $app->repo('Agent')->find($semCpf->profile->id);
        $campo = $this->plugin()->config['metadataFieldCPF'];
        $app->disableAccessControl();
        $perfil->$campo = self::CPF;
        $perfil->save(true);
        $app->em->flush();
        $app->enableAccessControl();
        $app->em->clear();

        [$status] = $this->devolver((int) $linha['id']);
        $this->assertSame(200, $status);
        $this->processarEnvios();
        $this->assertSituacao('enviado', $this->solicitacoes()[0], 'com CPF no cadastro, devolver à fila deveria enviar');
    }

    /**
     * Enviada já foi; pendente já está na fila.
     *
     * @dataProvider situacoesQueNaoVoltam
     */
    function testSoRecusadaVolta(string $situacao)
    {
        $id = $this->recusada();

        $this->alterarLinha($id, ['send_status' => $situacao]);

        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status, $corpo] = $this->devolver($id);

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $corpo);
        $this->assertSituacao($situacao, $this->solicitacoes()[0]);
    }

    public static function situacoesQueNaoVoltam(): array
    {
        return [
            'enviado' => ['enviado'],
            'pendente' => ['pendente'],
        ];
    }

    function testSolicitacaoInexistente()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status] = $this->devolver(999999999);

        $this->assertSame(404, $status);
    }
}
