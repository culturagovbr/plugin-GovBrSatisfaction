<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Outcome;
use GovBrSatisfaction\Bsc\Result;
use GovBrSatisfaction\Controllers\Requests;
use GovBrSatisfaction\Entities\SatisfactionDispatch;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Services\DispatchLog;
use MapasCulturais\App;
use Tests\Traits\RequestFactory;

/** O que o histórico de envios recebe. */
class DispatchesEndpointTest extends TestCase
{
    use RequestFactory;

    protected function consultar(int $id, int $pagina = 1): array
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'dispatches', [], ['id' => $id, 'pagina' => $pagina]), false);

        return [
            $app->response->getStatusCode(),
            json_decode((string) $app->response->getBody(), true),
        ];
    }

    protected function idDaSolicitacao(): int
    {
        return (int) $this->solicitacoes()[0]['id'];
    }

    protected function entrarComoAdministrador(): \MapasCulturais\Entities\User
    {
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        return $admin;
    }

    function testEnvioVemComATentativa()
    {
        $this->publicarEspaco();
        $this->processarEnvios();
        $this->entrarComoAdministrador();

        [$status, $dados] = $this->consultar($this->idDaSolicitacao());

        $this->assertSame(200, $status);
        $this->assertSame(1, $dados['total']);
        $this->assertSame(1, $dados['paginas']);

        $envio = $dados['envios'][0];

        $this->assertSame($this->envios()[0]['uuid'], $envio['uuid']);
        $this->assertSame('enviado', $envio['situacao']);
        $this->assertSame('registro', $envio['origem']);
        $this->assertNull($envio['autor']);
        $this->assertIsInt($envio['criadoEm']);
        $this->assertIsInt($envio['finalizadoEm']);

        $tentativa = $envio['tentativas'][0];

        $this->assertSame(1, $tentativa['numero']);
        $this->assertSame(3, $tentativa['maximo']);
        $this->assertSame('simulado', $tentativa['situacao']);
        $this->assertSame('POST', $tentativa['metodo']);
        $this->assertSame(200, $tentativa['httpStatus']);
        $this->assertSame('776.***.***-68', $tentativa['payload']['cpfCidadao']);
        $this->assertStringContainsString('FIXTURE', $tentativa['resposta']);
        $this->assertFalse($tentativa['respostaCortada']);
        $this->assertIsInt($tentativa['enviadoEm']);
        $this->assertStringNotContainsString(self::CPF, json_encode($dados));
    }

    /** Tentativas em ordem, envio sem tentativa vem vazio, autor só com o id. */
    function testTentativasEmOrdemEAutorSoComId()
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueDevolve(new Result(Outcome::Retry, 503, 'no healthy upstream'))]);
        $this->processarEnvios();
        $this->processarEnvios();

        $admin = $this->entrarComoAdministrador();
        $solicitacao = App::i()->repo(SatisfactionRequest::class)->find($this->idDaSolicitacao());
        (new DispatchLog())->start($solicitacao, SatisfactionDispatch::ORIGIN_RETRY_NOW, $admin);

        [, $dados] = $this->consultar($solicitacao->id);

        [$novo, $antigo] = $dados['envios'];

        $this->assertSame(['id' => $admin->id], $novo['autor']);
        $this->assertSame('tentar-agora', $novo['origem']);
        $this->assertSame([], $novo['tentativas']);
        $this->assertNull($novo['finalizadoEm']);

        $this->assertSame('substituido', $antigo['situacao']);
        $this->assertSame([1, 2], array_column($antigo['tentativas'], 'numero'));
        $this->assertSame([503, 503], array_column($antigo['tentativas'], 'httpStatus'));
        $this->assertSame('no healthy upstream', $antigo['tentativas'][0]['detalhe']);
    }

    function testMaisNovoPrimeiroEPaginado()
    {
        $this->publicarEspaco();
        $solicitacao = App::i()->repo(SatisfactionRequest::class)->find($this->idDaSolicitacao());
        $log = new DispatchLog();

        $total = Requests::DISPATCHES_PER_PAGE + 2;
        $uuids = [];

        for ($i = 0; $i < $total; $i++) {
            $uuids[] = $log->start($solicitacao, SatisfactionDispatch::ORIGIN_REQUEUE)->uuid;
        }

        $this->entrarComoAdministrador();

        [, $primeira] = $this->consultar($solicitacao->id);
        [, $segunda] = $this->consultar($solicitacao->id, 2);

        $this->assertSame($total, $primeira['total']);
        $this->assertSame(2, $primeira['paginas']);
        $this->assertCount(Requests::DISPATCHES_PER_PAGE, $primeira['envios']);
        $this->assertSame(
            array_reverse($uuids),
            array_merge(array_column($primeira['envios'], 'uuid'), array_column($segunda['envios'], 'uuid'))
        );
    }

    /** Dado gravado por extenso sai mascarado. */
    function testDadoGravadoPorExtensoSaiMascarado()
    {
        $this->publicarEspaco();
        $this->processarEnvios();

        $this->conn()->executeStatement(
            'UPDATE govbr_satisfaction_attempt SET payload = ?, response = ?, detail = ?, response_headers = ?',
            [
                '{"cpfCidadao":"77689062768","email":"maria.silva@example.com"}',
                '{"recebido":{"email":"maria.silva@example.com"}}',
                'CPF 77689062768 inválido',
                '["X-Cidadao: maria.silva@example.com"]',
            ]
        );
        App::i()->em->clear();

        $this->entrarComoAdministrador();
        [, $dados] = $this->consultar($this->idDaSolicitacao());

        $corpo = json_encode($dados);

        $this->assertStringNotContainsString(self::CPF, $corpo);
        $this->assertStringNotContainsString('maria.silva', $corpo);
        $this->assertSame('CPF *** inválido', $dados['envios'][0]['tentativas'][0]['detalhe']);
    }

    function testSemEnviosVemListaVazia()
    {
        $this->publicarEspaco();
        $this->entrarComoAdministrador();

        [$status, $dados] = $this->consultar($this->idDaSolicitacao());

        $this->assertSame(200, $status);
        $this->assertSame([], $dados['envios']);
        $this->assertSame(0, $dados['total']);
    }

    function testSolicitacaoInexistenteDa404()
    {
        $this->entrarComoAdministrador();

        [$status, $dados] = $this->consultar(999999999);

        $this->assertSame(404, $status);
        $this->assertArrayHasKey('error', $dados);
    }
}
