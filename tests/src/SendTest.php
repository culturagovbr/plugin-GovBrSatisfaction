<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Outcome;
use GovBrSatisfaction\Bsc\Result;
use GovBrSatisfaction\Services\SatisfactionSender;
use MapasCulturais\App;
use Tests\Traits\ProjectDirector;

/** O envio: situação, carimbo, retentativa. */
class SendTest extends TestCase
{
    use ProjectDirector;

    /** Recusa real do BSC de homologação, com o motivo em `subErrors`. */
    const CORPO_RECUSA = '{"status":"BAD_REQUEST","message":"Parâmetro(s) de entrada inválido(s)",'
        . '"subErrors":[{"message":"Favor preencher o campo linkBotao."}],"codigoErro":1790278898}';

    function testEnvioQueNaoSaiVoltaAPendente()
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueDevolve(new Result(Outcome::Retry, 503, 'no healthy upstream'))]);

        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('pendente', $linha, 'envio que não saiu ficou marcado como enviado');
        $this->assertNull($linha['send_timestamp'], 'carimbo de envio ficou preenchido sem envio');
    }

    /** Recusa definitiva sai da fila com o que a API respondeu. */
    function testRecusaDefinitivaNaoContaComoEnviada()
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueDevolve(
            new Result(Outcome::Rejected, 400, 'Parâmetro(s) de entrada inválido(s)', self::CORPO_RECUSA)
        )]);

        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('recusado', $linha, 'recusa do BSC ficou marcada como enviada');
        $this->assertNull($linha['send_timestamp'], 'recusa ficou com carimbo de envio');
        $this->assertSame(400, (int) $linha['send_http_status']);
        $this->assertSame('Parâmetro(s) de entrada inválido(s)', $linha['send_detail']);
        $this->assertSame(self::CORPO_RECUSA, $linha['send_response']);
    }

    function testDepoisDeVoltarAPendenteAProximaVarreduraEnvia()
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueDevolve(new Result(Outcome::Retry, 503, 'no healthy upstream'))]);
        $this->processarEnvios();

        $this->configurar(['client' => null]);
        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('enviado', $linha);
        $this->assertNotNull($linha['send_timestamp']);
    }

    function testNasceComoPendente()
    {
        $this->publicarEspaco();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('pendente', $linha);
        $this->assertNull($linha['send_timestamp']);
    }

    function testJobMarcaComoEnviado()
    {
        $this->publicarEspaco();
        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('enviado', $linha);
        $this->assertNotNull($linha['send_timestamp'], 'enviado sem carimbo de tempo');
    }

    function testSegundaVarreduraNaoReenvia()
    {
        $this->publicarEspaco();
        $this->processarEnvios();

        $carimbo = $this->solicitacoes()[0]['send_timestamp'];

        $this->processarEnvios();

        $this->assertSame($carimbo, $this->solicitacoes()[0]['send_timestamp']);
    }

    function testUsuarioSemCpfNaoEnvia()
    {
        $semCpf = $this->userDirector->createUser();
        $this->login($semCpf);

        $this->publicar($this->spaceDirector()->createSpace($semCpf->profile));
        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('sem-cpf', $linha);
        $this->assertNull($linha['send_timestamp']);
    }

    /** Os endereços vêm da requisição que publicou. */
    function testGuardaOsEnderecosDoGatilho()
    {
        $anterior = $_SERVER['SERVER_ADDR'] ?? null;
        $_SERVER['SERVER_ADDR'] = '10.0.0.5';

        try {
            $this->publicarEspaco();
        } finally {
            if ($anterior === null) {
                unset($_SERVER['SERVER_ADDR']);
            } else {
                $_SERVER['SERVER_ADDR'] = $anterior;
            }
        }

        $linha = $this->solicitacoes()[0];

        $this->assertSame('10.0.0.5', $linha['ip_origem'], 'o IP do servidor não foi capturado no gatilho');

        $this->processarEnvios();

        $enviado = json_decode($this->solicitacoes()[0]['send_payload'], true);

        $this->assertSame('10.0.0.5', $enviado['ipOrigem']);
        $this->assertNotEmpty($enviado['ipUsuario'], 'ipUsuario é obrigatório no contrato');
    }

    /** Um job por solicitação, para agora. */
    function testCadaSolicitacaoTemOProprioJob()
    {
        $this->publicarEspaco();
        $this->publicar($this->projectDirector->createProject($this->cidadao->profile));
        App::i()->em->flush();

        $ids = array_column($this->solicitacoes(), 'id');
        $jobs = $this->conn()->fetchAllAssociative(
            'SELECT metadata, next_execution_timestamp, create_timestamp FROM job WHERE name = ?',
            ['govbr-satisfaction-send']
        );

        $this->assertCount(2, $jobs, 'deveria haver um job por solicitação');

        $agendados = array_map(fn($j) => (string) json_decode($j['metadata'], true)['request_id'], $jobs);
        sort($ids);
        sort($agendados);
        $this->assertSame(array_map('strval', $ids), $agendados);

        foreach ($jobs as $job) {
            $this->assertLessThanOrEqual($job['create_timestamp'], $job['next_execution_timestamp'], 'job novo deveria ser para agora');
        }
    }

    /** Publicação durante uma queda é tentada na hora. */
    function testPublicarDuranteQuedaNaoEsperaOJobAdiado()
    {
        $this->publicarEspaco();
        $espaco = $this->servico('espaco');

        // BSC fora só para o espaço: o job dele se reagenda com failures = 1
        $this->configurar(['client' => $this->clienteQueDecide(fn(array $payload) => $payload['servico'] === $espaco
            ? new Result(Outcome::Retry, 503, 'no healthy upstream')
            : new Result(Outcome::Sent, 200, null, '{"emailEnviado":true}')
        )]);
        $this->processarEnvios();

        $jobEspaco = $this->conn()->fetchAssociative(
            "SELECT metadata, create_timestamp, next_execution_timestamp FROM job WHERE name = ? AND metadata::text LIKE ?",
            ['govbr-satisfaction-send', '%"failures":1%']
        );
        $this->assertNotFalse($jobEspaco, 'o job da linha presa deveria ter sido reagendado');
        $this->assertGreaterThan($jobEspaco['create_timestamp'], $jobEspaco['next_execution_timestamp'], 'o reagendamento deveria ser para depois');

        // publicação nova durante a queda: enviada na hora
        $this->publicar($this->projectDirector->createProject($this->perfilAtual()));
        $this->processarEnvios();

        $this->assertSituacao('enviado', $this->solicitacoes("servico = '{$this->servico('projeto')}'")[0], 'a publicação nova esperou o job adiado');
        $this->assertSituacao('pendente', $this->solicitacoes("servico = '{$espaco}'")[0]);
    }

    /** Retentativa tem teto. */
    function testDesisteDepoisDoLimiteDeTentativas()
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueDevolve(
            new Result(Outcome::Retry, 500, 'Erro interno', '{"message":"Erro interno"}')
        )]);

        for ($i = 0; $i < SatisfactionSender::MAX_ATTEMPTS; $i++) {
            $this->processarEnvios();
        }

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('recusado', $linha, 'a linha continuou retentando depois do limite');
        $this->assertSame(SatisfactionSender::MAX_ATTEMPTS, (int) $linha['send_attempts']);
    }

    /**
     * Transporte fora não consome tentativas.
     *
     * @dataProvider falhasDeTransporte
     */
    function testQuedaDoTransporteNaoConsomeTentativas(?int $status, string $detalhe)
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueDevolve(new Result(Outcome::Retry, $status, $detalhe))]);

        for ($i = 0; $i < SatisfactionSender::MAX_ATTEMPTS + 2; $i++) {
            $this->processarEnvios();
        }

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('pendente', $linha, "queda do transporte ({$detalhe}) virou recusa");
        $this->assertSame(0, (int) $linha['send_attempts'], 'falha de transporte consumiu tentativa');
    }

    public static function falhasDeTransporte(): array
    {
        return [
            'token negado' => [null, 'não foi possível obter token do BSC'],
            'rede antes do despacho' => [null, 'falha de rede antes do despacho: Could not resolve host'],
            'gateway redirecionando' => [302, 'resposta inesperada HTTP 302'],
            'proxy sem upstream' => [503, 'no healthy upstream'],
            'gateway timeout' => [504, 'upstream request timeout'],
        ];
    }

    /** Cliente que lança vira falha da linha. */
    function testClienteQueLancaNaoDeixaALinhaComoEnviada()
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueLanca(new \RuntimeException('curl_init falhou'))]);

        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('pendente', $linha, 'a exceção deveria deixar a linha pendente');
        $this->assertNull($linha['send_timestamp']);
        $this->assertSame(1, (int) $linha['send_attempts']);
        $this->assertStringContainsString('curl_init falhou', $linha['send_detail']);

        for ($i = 1; $i < SatisfactionSender::MAX_ATTEMPTS; $i++) {
            $this->processarEnvios();
        }

        $this->assertSituacao('recusado', $this->solicitacoes()[0], 'a exceção deveria esgotar o teto de tentativas');
    }

    /**
     * Um 500 de uma linha não segura as outras.
     */
    function testFalhaDeUmaLinhaNaoTravaAsSeguintes()
    {
        $this->publicarEspaco();
        $this->publicar($this->projectDirector->createProject($this->cidadao->profile));

        $espaco = $this->servico('espaco');

        $this->configurar(['client' => $this->clienteQueDecide(fn(array $payload) => $payload['servico'] === $espaco
            ? new Result(Outcome::Retry, 500, 'Erro interno', '{"message":"Erro interno"}')
            : new Result(Outcome::Sent, 200, null, '{"emailEnviado":true}')
        )]);

        $this->processarEnvios();

        $linhaEspaco = $this->solicitacoes("servico = '{$espaco}'")[0];
        $linhaProjeto = $this->solicitacoes("servico = '{$this->servico('projeto')}'")[0];

        $this->assertSituacao('pendente', $linhaEspaco);
        $this->assertSame(1, (int) $linhaEspaco['send_attempts']);

        $this->assertSituacao('enviado', $linhaProjeto, 'o 500 de outra linha travou esta');
    }

    /** Transporte fora: cada job se reagenda. */
    function testQuedaDoTransporteReagendaCadaJob()
    {
        $this->publicarEspaco();
        $this->publicar($this->projectDirector->createProject($this->cidadao->profile));

        $cliente = $this->clienteQueDevolve(new Result(Outcome::Retry, 503, 'no healthy upstream'));

        $this->configurar(['client' => $cliente]);
        $this->processarEnvios();

        $this->assertSame(2, $cliente->chamadas, 'cada solicitação deveria ter sido tentada');
        $this->assertSame(2, $this->contar("send_status = 'pendente' AND send_attempts = 0"));

        $reagendados = (int) $this->conn()->fetchOne(
            "SELECT count(*) FROM job WHERE name = ? AND next_execution_timestamp > create_timestamp",
            ['govbr-satisfaction-send']
        );
        $this->assertSame(2, $reagendados, 'os dois jobs deveriam estar reagendados para depois');
    }

    /** Job sem request_id distribui um job por pendente. */
    function testJobAntigoDistribuiUmJobPorPendente()
    {
        $this->publicarEspaco();
        $this->conn()->executeStatement('DELETE FROM job WHERE name = ?', ['govbr-satisfaction-send']);

        App::i()->enqueueJob(\GovBrSatisfaction\Jobs\SendSatisfactionRequestJob::SLUG, []);

        // primeira rodada: o job antigo distribui; segunda: o job da linha envia
        $this->processarEnvios();
        $this->assertSame(1, (int) $this->conn()->fetchOne(
            "SELECT count(*) FROM job WHERE name = ? AND metadata::text LIKE '%request_id%'",
            ['govbr-satisfaction-send']
        ), 'o job antigo deveria ter gerado um job para a pendente');

        $this->processarEnvios();
        $this->assertSituacao('enviado', $this->solicitacoes()[0]);
    }
}
