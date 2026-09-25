<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Outcome;
use GovBrSatisfaction\Entities\SatisfactionDispatch;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Services\DispatchLog;
use MapasCulturais\App;

/** Histórico de envios e tentativas. */
class DispatchLogTest extends TestCase
{
    const PAYLOAD = [
        'cpfCidadao' => self::CPF,
        'email' => 'maria.silva@example.com',
        'nomeCidadao' => 'Maria da Silva',
        'ipUsuario' => '200.130.5.7',
        'servico' => '13683',
    ];

    protected DispatchLog $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = new DispatchLog();
    }

    protected function solicitacao(): SatisfactionRequest
    {
        $this->publicarEspaco();

        return App::i()->repo(SatisfactionRequest::class)->find((int) $this->solicitacoes()[0]['id']);
    }

    function testAbreEnvioPendenteComUuidEAutor()
    {
        $admin = $this->userDirector->createUser('saasSuperAdmin');
        $envio = $this->log->start($this->solicitacao(), SatisfactionDispatch::ORIGIN_REQUEUE, $admin);

        $linha = $this->envios()[0];

        $this->assertSame('pendente', $linha['state']);
        $this->assertSame('devolucao', $linha['origin']);
        $this->assertSame($admin->id, (int) $linha['user_id']);
        $this->assertNull($linha['finish_timestamp']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $linha['uuid']
        );
        $this->assertSame($envio->id, $this->log->find($linha['uuid'])->id);
    }

    function testNovoEnvioSubstituiOPendente()
    {
        $solicitacao = $this->solicitacao();
        $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REGISTRATION);
        $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_RETRY_NOW);

        [$antigo, $novo] = $this->envios();

        $this->assertSame('substituido', $antigo['state']);
        $this->assertNotNull($antigo['finish_timestamp']);
        $this->assertSame('pendente', $novo['state']);
    }

    function testEnvioEncerradoNaoEhSubstituido()
    {
        $solicitacao = $this->solicitacao();
        $primeiro = $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REGISTRATION);

        $this->assertTrue($this->log->finish($primeiro, SatisfactionDispatch::STATE_SENT));

        $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REQUEUE);

        $this->assertSame('enviado', $this->envios()[0]['state']);
    }

    /** Quem ainda tem o envio substituído em memória não consegue encerrá-lo. */
    function testEncerrarSoSaiDePendente()
    {
        $solicitacao = $this->solicitacao();
        $antigo = $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REGISTRATION);
        $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REQUEUE);

        $this->assertFalse($this->log->finish($antigo, SatisfactionDispatch::STATE_SENT));
        $this->assertSame('substituido', $this->envios()[0]['state']);
        $this->assertSame('substituido', $antigo->state);
    }

    /** A listagem traz o estado do banco, mesmo com o envio antigo em memória. */
    function testListaTrazASituacaoAtualDoEnvioEmMemoria()
    {
        $solicitacao = $this->solicitacao();
        $antigo = $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REGISTRATION);
        $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REQUEUE);

        $pagina = $this->log->findByRequest($solicitacao->id);

        $this->assertSame($antigo->id, $pagina[1]['dispatch']->id);
        $this->assertSame('substituido', $pagina[1]['dispatch']->state);
    }

    function testTentativaGravaSoDadoMascarado()
    {
        $envio = $this->log->start($this->solicitacao(), SatisfactionDispatch::ORIGIN_REGISTRATION);

        $this->log->recordAttempt(
            $envio,
            number: 1,
            maxAttempts: 3,
            outcome: Outcome::Rejected->value,
            payload: self::PAYLOAD,
            httpStatus: 400,
            response: '{"message":"CPF 77689062768 inválido","recebido":{"email":"maria.silva@example.com"}}',
            detail: 'CPF 77689062768 inválido',
            method: 'POST',
            endpoint: 'http://bsc.invalido.teste/api/avaliacao/completa',
            responseHeaders: ['HTTP/1.1 400 Bad Request', 'X-Cidadao: maria.silva@example.com'],
            durationMs: 144,
        );

        $linha = $this->tentativas()[0];

        foreach ([self::CPF, 'maria.silva', 'da Silva', '200.130.5.7'] as $pessoal) {
            $this->assertStringNotContainsString($pessoal, json_encode($linha), "{$pessoal} gravado por extenso");
        }

        $payload = json_decode($linha['payload'], true);

        $this->assertSame('776.***.***-68', $payload['cpfCidadao']);
        $this->assertSame('13683', $payload['servico']);
        $this->assertSame('CPF *** inválido', $linha['detail']);
        $this->assertSame(['HTTP/1.1 400 Bad Request', 'X-Cidadao: ***'], json_decode($linha['response_headers'], true));
        $this->assertSame('recusado', $linha['outcome']);
        $this->assertSame(400, (int) $linha['http_status']);
        $this->assertSame(144, (int) $linha['duration_ms']);
        $this->assertSame('POST', $linha['method']);
        $this->assertFalse((bool) $linha['response_truncated']);
    }

    /** Resposta grande é cortada sem quebrar caractere. */
    function testRespostaGrandeEhCortadaSemQuebrarCaractere()
    {
        $envio = $this->log->start($this->solicitacao(), SatisfactionDispatch::ORIGIN_REGISTRATION);

        $this->log->recordAttempt($envio, 1, 3, Outcome::Retry->value, response: str_repeat('ã', 40000));

        $linha = $this->tentativas()[0];

        $this->assertTrue((bool) $linha['response_truncated']);
        $this->assertLessThanOrEqual(DispatchLog::RESPONSE_MAX, strlen($linha['response']));
        $this->assertTrue(mb_check_encoding($linha['response'], 'UTF-8'));
    }

    function testRespostaComBytesInvalidosEhGravada()
    {
        $envio = $this->log->start($this->solicitacao(), SatisfactionDispatch::ORIGIN_REGISTRATION);

        $this->log->recordAttempt(
            $envio,
            1,
            3,
            Outcome::Retry->value,
            response: "erro \xC3\x28 no proxy",
            responseHeaders: ["Server: \xE9"],
        );

        $linha = $this->tentativas()[0];

        $this->assertTrue(mb_check_encoding($linha['response'], 'UTF-8'));
        $this->assertStringStartsWith('erro ', $linha['response']);
        $this->assertTrue(mb_check_encoding($linha['response_headers'], 'UTF-8'));
    }

    function testListaDoMaisNovoAoMaisAntigoComTentativasEmOrdem()
    {
        $solicitacao = $this->solicitacao();
        $admin = $this->userDirector->createUser('saasSuperAdmin');

        $primeiro = $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REGISTRATION);
        $this->log->recordAttempt($primeiro, 1, 3, Outcome::Retry->value, httpStatus: 500);
        $this->log->recordAttempt($primeiro, 2, 3, Outcome::Sent->value, httpStatus: 200);
        $this->log->finish($primeiro, SatisfactionDispatch::STATE_SENT);

        $segundo = $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REQUEUE, $admin);
        $terceiro = $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_RETRY_NOW, $admin);

        $pagina = $this->log->findByRequest($solicitacao->id);

        $this->assertSame(
            [$terceiro->id, $segundo->id, $primeiro->id],
            array_map(fn($envio) => $envio['dispatch']->id, $pagina)
        );
        $this->assertSame([1, 2], array_map(fn($tentativa) => $tentativa->number, $pagina[2]['attempts']));
        $this->assertSame([], $pagina[0]['attempts']);
        $this->assertSame($admin->id, $pagina[0]['userId']);
        $this->assertNull($pagina[2]['userId']);
        $this->assertSame(3, $this->log->countByRequest($solicitacao->id));

        $segunda = $this->log->findByRequest($solicitacao->id, skip: 1, limit: 1);

        $this->assertSame([$segundo->id], array_map(fn($envio) => $envio['dispatch']->id, $segunda));
    }

    function testApagarASolicitacaoApagaOHistorico()
    {
        $solicitacao = $this->solicitacao();
        $envio = $this->log->start($solicitacao, SatisfactionDispatch::ORIGIN_REGISTRATION);
        $this->log->recordAttempt($envio, 1, 3, Outcome::Sent->value);

        $this->conn()->executeStatement('DELETE FROM govbr_satisfaction_request WHERE id = ?', [$solicitacao->id]);

        $this->assertSame([], $this->envios());
        $this->assertSame([], $this->tentativas());
    }
}
