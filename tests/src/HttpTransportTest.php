<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\HttpClient;
use GovBrSatisfaction\Bsc\Outcome;

/** Transporte HTTP contra um servidor simulado no loopback. */
class HttpTransportTest extends TestCase
{
    private static $server = null;
    private static string $base = '';

    const PAYLOAD = [
        'cacheEvict' => false,
        'cpfCidadao' => '77689062768',
        'email' => 'cidadao@example.com',
        'nomeCidadao' => 'Maria da Silva',
        'servico' => '13683',
        'dataEtapa' => '24/09/2026',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        array_map('unlink', glob(sys_get_temp_dir() . '/govbr-fake-*') ?: []);

        $port = self::freePort();
        $router = __DIR__ . '/Fake/bsc-server.php';

        self::$server = proc_open(
            ['php', '-S', "127.0.0.1:{$port}", $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );

        self::$base = "http://127.0.0.1:{$port}";

        // espera o servidor aceitar conexão
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($socket) {
                fclose($socket);

                return;
            }

            usleep(100000);
        }

        self::fail('o BSC falso não subiu');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        array_map('unlink', glob(sys_get_temp_dir() . '/govbr-fake-*') ?: []);

        parent::tearDownAfterClass();
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }

    private function client(string $scenario, int $timeout = 10): HttpClient
    {
        return new HttpClient(
            self::$base . "/{$scenario}",
            self::$base . "/{$scenario}/token",
            'id-teste',
            'segredo-teste',
            $timeout,
            5
        );
    }

    /** Token com credenciais em cabeçalho; POST com Bearer e JSON. */
    function testEnviaComTokenEHeaders()
    {
        $r = $this->client('ok')->send(self::PAYLOAD);

        $this->assertSame(Outcome::Sent, $r->outcome);
        $this->assertSame(200, $r->status);
        $this->assertSame('protocolo P1', $r->detail);

        $eco = json_decode($r->body, true);

        $this->assertSame(self::PAYLOAD, $eco['recebido'], 'o corpo que chegou não é o que foi montado');
        $this->assertSame('Bearer t1', $eco['authorization']);
        $this->assertStringStartsWith('application/json', $eco['contentType']);
    }

    /** A troca vem junto: método, endpoint, duração e cabeçalhos da resposta. */
    function testDevolveARequisicaoParaOHistorico()
    {
        $r = $this->client('ok')->send(self::PAYLOAD);

        $this->assertNotNull($r->exchange);
        $this->assertSame('POST', $r->exchange->method);
        $this->assertSame(self::$base . '/ok/api/avaliacao/completa', $r->exchange->endpoint);
        $this->assertFalse($r->exchange->simulated);
        $this->assertGreaterThanOrEqual(0, $r->exchange->durationMs);
        $this->assertStringStartsWith('HTTP/1.1 200', $r->exchange->responseHeaders[0]);

        foreach ($r->exchange->responseHeaders as $linha) {
            $this->assertStringNotContainsStringIgnoringCase('authorization', $linha, 'cabeçalho da requisição no histórico');
        }
    }

    /** Resposta lenta: a duração acompanha. */
    function testDuracaoMedeARequisicao()
    {
        $r = $this->client('lento', timeout: 1)->send(self::PAYLOAD);

        $this->assertSame(Outcome::Retry, $r->outcome);
        $this->assertGreaterThanOrEqual(900, $r->exchange->durationMs);
    }

    /** Token expirado: renova e refaz o POST. */
    function testRenovaOTokenExpiradoERefazOPost()
    {
        $client = $this->client('expira');

        $primeiro = $client->send(self::PAYLOAD);
        $this->assertSame(Outcome::Sent, $primeiro->outcome);
        $this->assertSame('protocolo P-t1', $primeiro->detail);

        // mesma instância: t1 reutilizado, o falso devolve 401
        $segundo = $client->send(self::PAYLOAD);
        $this->assertSame(Outcome::Sent, $segundo->outcome, 'o 401 do token expirado deveria ter sido renovado');
        $this->assertSame('protocolo P-t2', $segundo->detail);

        $this->assertSame('2', file_get_contents(sys_get_temp_dir() . '/govbr-fake-expira-token'), 'o token deveria ter sido pedido duas vezes');
    }

    /** 401 no primeiro POST é recusa. */
    function test401NoPrimeiroPostEhRecusa()
    {
        $r = $this->client('credencial')->send(self::PAYLOAD);

        $this->assertSame(Outcome::Rejected, $r->outcome);
        $this->assertSame(401, $r->status);
    }

    /** Token sem accessToken. */
    function testTokenSemAccessTokenVoltaParaAFila()
    {
        $r = $this->client('semtoken')->send(self::PAYLOAD);

        $this->assertSame(Outcome::Retry, $r->outcome);
        $this->assertNull($r->status);
        $this->assertStringContainsString('token', $r->detail);
    }

    /** Token vazio não fica guardado. */
    function testTokenVazioNaoFicaGuardado()
    {
        $client = $this->client('tokenvazio');

        $this->assertSame(Outcome::Retry, $client->send(self::PAYLOAD)->outcome);
        $this->assertSame(Outcome::Retry, $client->send(self::PAYLOAD)->outcome);
    }

    function testProxyEmTextoPuroEhTransitorio()
    {
        $r = $this->client('proxy503')->send(self::PAYLOAD);

        $this->assertSame(Outcome::Retry, $r->outcome);
        $this->assertSame(503, $r->status);
        $this->assertSame('no healthy upstream', $r->detail);
    }

    function testRecusaChegaComOCorpoLimpo()
    {
        $r = $this->client('recusa')->send(self::PAYLOAD);

        $this->assertSame(Outcome::Rejected, $r->outcome);
        $this->assertSame(400, $r->status);

        $corpo = json_decode($r->body, true);
        $this->assertArrayHasKey('subErrors', $corpo);
        $this->assertArrayNotHasKey('stackTrace', $corpo);
    }

    /** Porta fechada. */
    function testPortaFechadaVoltaParaAFila()
    {
        $client = new HttpClient('http://127.0.0.1:9/x', 'http://127.0.0.1:9/x/token', 'id-teste', 'segredo-teste', 2, 1);

        $r = $client->send(self::PAYLOAD);

        $this->assertSame(Outcome::Retry, $r->outcome);
        $this->assertNull($r->status);
    }

    /** Timeout total. Por último: o servidor simulado é de uma thread só. */
    function testTimeoutVoltaParaAFila()
    {
        $r = $this->client('lento', timeout: 1)->send(self::PAYLOAD);

        $this->assertSame(Outcome::Retry, $r->outcome);
        $this->assertNull($r->status);
        $this->assertStringContainsString('falha de rede', $r->detail);
    }
}
