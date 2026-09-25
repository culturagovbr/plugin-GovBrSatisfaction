<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Jobs\SendSatisfactionRequestJob;
use MapasCulturais\App;
use Tests\Traits\EventDirector;
use Tests\Traits\ProjectDirector;
use Tests\Traits\RequestFactory;

/** Devolver todas as recusadas do filtro. */
class RequeueAllTest extends TestCase
{
    use EventDirector;
    use ProjectDirector;
    use RequestFactory;

    /** Quatro recusadas, de dois usuários. */
    protected function recusadas(): array
    {
        $this->publicarEspaco();
        $this->publicar($this->projectDirector->createProject($this->cidadao->profile));
        $this->publicar($this->eventDirector->createEvent($this->cidadao->profile));

        $outro = $this->criarCidadao();
        $this->login($outro);
        $this->publicar($this->spaceDirector()->createSpace($outro->profile));

        $ids = array_map('intval', array_column($this->solicitacoes(), 'id'));

        foreach ($ids as $id) {
            $this->alterarLinha($id, ['send_status' => 'recusado', 'send_attempts' => 3, 'send_http_status' => 500, 'send_detail' => 'Erro interno']);
        }

        return $ids;
    }

    protected function devolverTodas(array $params = []): array
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->POST('govbr-satisfaction-requests', 'requeueAll', [], $params), false);

        return [
            $app->response->getStatusCode(),
            json_decode((string) $app->response->getBody(), true),
        ];
    }

    protected function jobsDoPlugin(): array
    {
        return $this->conn()->fetchAllAssociative(
            'SELECT metadata, create_timestamp, next_execution_timestamp FROM job WHERE name = ? ORDER BY next_execution_timestamp',
            [SendSatisfactionRequestJob::SLUG]
        );
    }

    function testUsuarioComumNaoDevolve()
    {
        $this->recusadas();
        $this->login($this->userDirector->createUser());

        [$status] = $this->devolverTodas();

        $this->assertSame(403, $status);
        $this->assertSame(4, $this->contar("send_status = 'recusado'"));
    }

    /** Um job por solicitação, escalonados. */
    function testDevolveTodasComJobsEscalonados()
    {
        $this->recusadas();
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        [$status, $corpo] = $this->devolverTodas();

        $this->assertSame(200, $status);
        $this->assertSame(4, $corpo['devolvidas']);
        $this->assertSame(0, $corpo['restantes']);
        $this->assertSame(SendSatisfactionRequestJob::BULK_INTERVAL, $corpo['intervalo']);

        $this->assertSame(4, $this->contar("send_status = 'pendente' AND send_attempts = 0"));
        $this->assertSame(0, $this->contar("send_status = 'recusado'"));

        $envios = $this->envios();
        $this->assertCount(4, $envios, 'um envio por solicitação');
        $this->assertSame(['lote'], array_values(array_unique(array_column($envios, 'origin'))));
        $this->assertSame([(string) $admin->id], array_values(array_unique(array_map('strval', array_column($envios, 'user_id')))));

        $jobs = $this->jobsDoPlugin();
        $this->assertCount(4, $jobs, 'um job por solicitação');

        $anterior = null;

        foreach ($jobs as $job) {
            $inicio = new \DateTime($job['next_execution_timestamp']);

            if ($anterior) {
                $this->assertGreaterThanOrEqual(
                    SendSatisfactionRequestJob::BULK_INTERVAL,
                    $inicio->getTimestamp() - $anterior->getTimestamp(),
                    'jobs vizinhos deveriam estar pelo menos BULK_INTERVAL afastados'
                );
            }

            $anterior = $inicio;
        }

        // o primeiro sai já; o último, (n-1) intervalos depois
        $primeiro = new \DateTime($jobs[0]['next_execution_timestamp']);
        $ultimo = new \DateTime($jobs[3]['next_execution_timestamp']);
        $this->assertSame(3 * SendSatisfactionRequestJob::BULK_INTERVAL, $ultimo->getTimestamp() - $primeiro->getTimestamp());
    }

    /** Respeita o filtro de serviço. */
    function testRespeitaOFiltroDeServico()
    {
        $this->recusadas();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status, $corpo] = $this->devolverTodas(['servico' => $this->servico('espaco')]);

        $this->assertSame(200, $status);
        $this->assertSame(2, $corpo['devolvidas'], 'dois usuários têm espaço recusado');
        $this->assertSame(2, $this->contar("send_status = 'pendente'"));
        $this->assertSame(2, $this->contar("send_status = 'recusado'"));
        $this->assertSame(0, $this->contar("send_status = 'recusado' AND servico = '{$this->servico('espaco')}'"));
    }

    /** Depois do lote envia; segundo clique não devolve nada. */
    function testDepoisDoLoteEnviaEUmSegundoCliqueNaoFazNada()
    {
        $this->recusadas();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $this->devolverTodas();

        [, $corpo] = $this->devolverTodas();
        $this->assertSame(0, $corpo['devolvidas']);

        $this->processarEnvios();

        $this->assertSame(4, $this->contar("send_status = 'enviado'"));
    }

    /** Sem-cpf e enviadas ficam de fora. */
    function testNaoTocaOutrasSituacoes()
    {
        [$a, $b] = $this->recusadas();
        $this->alterarLinha($a, ['send_status' => 'sem-cpf']);
        $this->alterarLinha($b, ['send_status' => 'enviado']);

        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [, $corpo] = $this->devolverTodas();

        $this->assertSame(2, $corpo['devolvidas']);
        $this->assertSame(1, $this->contar("send_status = 'sem-cpf'"));
        $this->assertSame(1, $this->contar("send_status = 'enviado'"));
    }

    /** Com busca aplicada, devolve só as recusadas que a lista mostra. */
    function testRespeitaABusca()
    {
        $this->recusadas();
        $outra = (int) $this->conn()->fetchOne(
            'SELECT user_id FROM govbr_satisfaction_request WHERE user_id <> ? LIMIT 1',
            [$this->cidadao->id]
        );

        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        $app = App::i();
        $app->reset();
        $app->run($this->requestFactory->GET('govbr-satisfaction-requests', 'index', [], ['situacao' => 'recusado', 'busca' => (string) $outra]), false);
        $naLista = json_decode((string) $app->response->getBody(), true)['total'];

        [$status, $corpo] = $this->devolverTodas(['servico' => '', 'busca' => (string) $outra]);

        $this->assertSame(200, $status);
        $this->assertSame(1, $naLista);
        $this->assertSame($naLista, $corpo['devolvidas'], 'devolveu mais do que a lista mostrava');
    }
}
