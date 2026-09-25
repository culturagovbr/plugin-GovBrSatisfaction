<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Outcome;
use GovBrSatisfaction\Entities\SatisfactionDispatch;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Jobs\PurgeSatisfactionHistoryJob;
use GovBrSatisfaction\Services\DispatchLog;
use GovBrSatisfaction\Services\PayloadVault;
use MapasCulturais\App;

/** Expurgo do payload cifrado e da resposta. */
class PurgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->conn()->executeStatement('DELETE FROM job WHERE name = ?', [PurgeSatisfactionHistoryJob::SLUG]);
    }

    /** Uma tentativa de 200 dias atrás e uma de hoje. */
    protected function antigaENova(): array
    {
        $this->publicarEspaco();

        $solicitacao = App::i()->repo(SatisfactionRequest::class)->find((int) $this->solicitacoes()[0]['id']);
        $log = new DispatchLog(PayloadVault::fromConfig('1:' . base64_encode(random_bytes(32))));
        $envio = $log->start($solicitacao, SatisfactionDispatch::ORIGIN_REGISTRATION);
        $payload = ['cpfCidadao' => self::CPF, 'servico' => '13683'];

        $antiga = $log->recordAttempt($envio, 1, 3, Outcome::Retry->value, payload: $payload, httpStatus: 500,
            response: '{"message":"Erro interno"}', detail: 'Erro interno', sentAt: new \DateTime('-200 days'));
        $nova = $log->recordAttempt($envio, 2, 3, Outcome::Sent->value, payload: $payload, httpStatus: 200,
            response: '{"protocolo":"P1"}', detail: 'protocolo P1');

        return [(int) $antiga->id, (int) $nova->id];
    }

    protected function tentativa(int $id): array
    {
        return $this->conn()->fetchAssociative('SELECT * FROM govbr_satisfaction_attempt WHERE id = ?', [$id]);
    }

    function testApagaSoOConteudoDasAntigas()
    {
        [$antiga, $nova] = $this->antigaENova();

        $this->assertSame(1, (new DispatchLog())->purge(new \DateTime('-180 days')));

        $limpa = $this->tentativa($antiga);
        $this->assertNull($limpa['payload_sealed']);
        $this->assertNull($limpa['response']);

        // o resto do histórico fica
        $this->assertNotNull($limpa['payload']);
        $this->assertSame('Erro interno', $limpa['detail']);
        $this->assertSame(500, (int) $limpa['http_status']);

        $intacta = $this->tentativa($nova);
        $this->assertNotNull($intacta['payload_sealed']);
        $this->assertNotNull($intacta['response']);
    }

    function testRodarDeNovoNaoContaAsJaLimpas()
    {
        $this->antigaENova();
        $log = new DispatchLog();

        $log->purge(new \DateTime('-180 days'));

        $this->assertSame(0, $log->purge(new \DateTime('-180 days')));
    }

    function testUsaOPrazoConfigurado()
    {
        [$antiga, $nova] = $this->antigaENova();

        $this->configurar(['retentionDays' => 365]);
        $this->assertSame(0, PurgeSatisfactionHistoryJob::purgeNow());

        $this->configurar(['retentionDays' => 180]);
        $this->assertSame(1, PurgeSatisfactionHistoryJob::purgeNow());
        $this->assertNull($this->tentativa($antiga)['payload_sealed']);
    }

    function testPrazoZeroDesliga()
    {
        [$antiga] = $this->antigaENova();
        $this->configurar(['retentionDays' => 0]);

        $this->assertSame(0, PurgeSatisfactionHistoryJob::purgeNow());
        $this->assertNotNull($this->tentativa($antiga)['payload_sealed']);
    }

    /** O envio agenda o expurgo diário uma vez só. */
    function testEnvioAgendaUmExpurgoDiario()
    {
        $this->publicarEspaco();
        $this->processarEnvios();
        PurgeSatisfactionHistoryJob::schedule();

        $jobs = $this->conn()->fetchAllAssociative(
            'SELECT interval_string, iterations, next_execution_timestamp FROM job WHERE name = ?',
            [PurgeSatisfactionHistoryJob::SLUG]
        );

        $this->assertCount(1, $jobs);
        $this->assertSame('+1 day', $jobs[0]['interval_string']);
        $this->assertSame(PurgeSatisfactionHistoryJob::ITERATIONS, (int) $jobs[0]['iterations']);
        $this->assertStringEndsWith('03:00:00', $jobs[0]['next_execution_timestamp']);
    }
}
