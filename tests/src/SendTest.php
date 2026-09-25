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

    /**
     * Publicar durante uma queda do BSC não anula o adiamento: o gatilho
     * enfileira sem `replace`.
     */
    function testPublicarDuranteQuedaNaoAnulaOAdiamento()
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueDevolve(new Result(Outcome::Retry, 503, 'no healthy upstream'))]);

        // a varredura falha e se adia com falhas = 1
        $this->processarEnvios();

        // outra publicação durante a queda
        $this->publicar($this->projectDirector->createProject($this->perfilAtual()));
        App::i()->em->flush();

        $jobs = $this->conn()->fetchAllAssociative(
            'SELECT metadata, create_timestamp, next_execution_timestamp FROM job WHERE name = ?',
            ['govbr-satisfaction-send']
        );

        $this->assertCount(1, $jobs, 'a publicação enfileirou uma segunda varredura');

        $job = $jobs[0];
        $metadata = json_decode((string) $job['metadata'], true);

        $this->assertSame(1, $metadata['failures'] ?? null, 'a publicação anulou a contagem de falhas da varredura');
        $this->assertGreaterThan(
            $job['create_timestamp'],
            $job['next_execution_timestamp'],
            'a publicação puxou a varredura adiada de volta para agora'
        );
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

    /**
     * O cliente lançando não pode deixar a linha "Disparada" sem disparo: vira
     * falha da linha, com a mensagem, e esgota o teto como um 500.
     */
    function testClienteQueLancaNaoDeixaALinhaComoEnviada()
    {
        $this->publicarEspaco();
        $this->configurar(['client' => $this->clienteQueLanca(new \RuntimeException('curl_init falhou'))]);

        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('pendente', $linha, 'a exceção deixou a linha marcada como enviada');
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

    /**
     * Já o transporte fora do ar para a varredura na primeira linha.
     */
    function testQuedaDoTransporteParaAVarredura()
    {
        $this->publicarEspaco();
        $this->publicar($this->projectDirector->createProject($this->cidadao->profile));

        $cliente = $this->clienteQueDevolve(new Result(Outcome::Retry, 503, 'no healthy upstream'));

        $this->configurar(['client' => $cliente]);
        $this->processarEnvios();

        $this->assertSame(1, $cliente->chamadas, 'a varredura seguiu depois de o transporte falhar');
        $this->assertSame(2, $this->contar("send_status = 'pendente'"));
    }
}
