<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Controllers\Requests;
use GovBrSatisfaction\Jobs\SendSatisfactionRequestJob;
use MapasCulturais\App;
use Tests\Traits\EventDirector;
use Tests\Traits\ProjectDirector;
use Tests\Traits\RequestFactory;

/** Devolver à fila as solicitações selecionadas. */
class RequeueSelectedTest extends TestCase
{
    use EventDirector;
    use ProjectDirector;
    use RequestFactory;

    /** Três solicitações: recusada, sem CPF e enviada. */
    protected function situacoes(): array
    {
        $this->publicarEspaco();
        $this->publicar($this->projectDirector->createProject($this->cidadao->profile));
        $this->publicar($this->eventDirector->createEvent($this->cidadao->profile));

        [$recusada, $semCpf, $enviada] = array_map('intval', array_column($this->solicitacoes(), 'id'));

        $this->alterarLinha($recusada, ['send_status' => 'recusado', 'send_attempts' => 3, 'send_http_status' => 500, 'send_detail' => 'Erro interno']);
        $this->alterarLinha($semCpf, ['send_status' => 'sem-cpf']);
        $this->alterarLinha($enviada, ['send_status' => 'enviado']);

        return [$recusada, $semCpf, $enviada];
    }

    protected function devolver(mixed $ids): array
    {
        $app = App::i();
        $app->reset();

        $app->run($this->requestFactory->POST('govbr-satisfaction-requests', 'requeueSelected', [], ['ids' => $ids]), false);

        return [
            $app->response->getStatusCode(),
            json_decode((string) $app->response->getBody(), true),
        ];
    }

    protected function situacaoDe(int $id): string
    {
        return $this->solicitacoes("id = {$id}")[0]['send_status'];
    }

    function testUsuarioComumNaoDevolve()
    {
        [$recusada] = $this->situacoes();
        $this->login($this->userDirector->createUser());

        [$status] = $this->devolver([$recusada]);

        $this->assertSame(403, $status);
        $this->assertSame('recusado', $this->situacaoDe($recusada));
    }

    /** Só recusadas e sem CPF voltam; o resto é ignorado. */
    function testDevolveSoAsQuePodemVoltar()
    {
        [$recusada, $semCpf, $enviada] = $this->situacoes();
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $this->login($admin);

        [$status, $corpo] = $this->devolver([$recusada, $semCpf, $enviada]);

        $this->assertSame(200, $status);
        $this->assertSame(2, $corpo['devolvidas']);
        $this->assertSame(1, $corpo['ignoradas']);
        $this->assertSame(SendSatisfactionRequestJob::BULK_INTERVAL, $corpo['intervalo']);

        $this->assertSame('pendente', $this->situacaoDe($recusada));
        $this->assertSame('pendente', $this->situacaoDe($semCpf));
        $this->assertSame('enviado', $this->situacaoDe($enviada));
        $this->assertSame(0, (int) $this->solicitacoes("id = {$recusada}")[0]['send_attempts']);

        $envios = $this->envios();
        $this->assertCount(2, $envios);
        $this->assertSame(['lote'], array_values(array_unique(array_column($envios, 'origin'))));
        $this->assertSame([(string) $admin->id], array_values(array_unique(array_map('strval', array_column($envios, 'user_id')))));
    }

    /** Jobs escalonados, como no lote. */
    function testJobsSaemEscalonados()
    {
        [$recusada, $semCpf] = $this->situacoes();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));
        $this->conn()->executeStatement('DELETE FROM job WHERE name = ?', [SendSatisfactionRequestJob::SLUG]);

        $this->devolver([$recusada, $semCpf]);

        $inicios = array_map(
            fn($data) => (new \DateTime($data))->getTimestamp(),
            $this->conn()->fetchFirstColumn(
                'SELECT next_execution_timestamp FROM job WHERE name = ? ORDER BY next_execution_timestamp',
                [SendSatisfactionRequestJob::SLUG]
            )
        );

        $this->assertCount(2, $inicios);
        $this->assertSame(SendSatisfactionRequestJob::BULK_INTERVAL, $inicios[1] - $inicios[0]);
    }

    function testAceitaIdsEmTextoERepetidos()
    {
        [$recusada] = $this->situacoes();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status, $corpo] = $this->devolver("{$recusada},{$recusada}");

        $this->assertSame(200, $status);
        $this->assertSame(1, $corpo['devolvidas']);
        $this->assertSame(0, $corpo['ignoradas']);
    }

    /**
     * Um id inválido recusa a seleção inteira.
     *
     * @dataProvider selecoesInvalidas
     */
    function testSelecaoInvalidaDa400(array $ids)
    {
        [$recusada] = $this->situacoes();
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status, $corpo] = $this->devolver([$recusada, ...$ids]);

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $corpo);
        $this->assertSame('recusado', $this->situacaoDe($recusada));
    }

    public static function selecoesInvalidas(): array
    {
        return [
            'zero' => [[0]],
            'negativo' => [[-3]],
            'com sufixo' => [['12x']],
            'texto' => [['abc']],
            'decimal' => [['1.5']],
            'lista aninhada' => [[[1]]],
        ];
    }

    function testSelecaoVaziaDa400()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        foreach ([[], ''] as $vazia) {
            [$status, $corpo] = $this->devolver($vazia);

            $this->assertSame(400, $status);
            $this->assertArrayHasKey('error', $corpo);
        }
    }

    function testAcimaDoTetoDa400()
    {
        $this->login($this->userDirector->createUser('saasSuperAdmin'));

        [$status, $corpo] = $this->devolver(range(1, Requests::BULK_MAX + 1));

        $this->assertSame(400, $status);
        $this->assertArrayHasKey('error', $corpo);
    }
}
