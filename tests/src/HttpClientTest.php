<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\HttpClient;
use GovBrSatisfaction\Bsc\Result;

/**
 * `interpretar()` é pura: o que o curl devolveu → desfecho. Nada toca a rede.
 */
class HttpClientTest extends TestCase
{
    /** Recusa real do BSC de homologação, com a pilha do Java resumida. */
    const CORPO_RECUSA = '{"status":"BAD_REQUEST","message":"Parâmetro(s) de entrada inválido(s)",'
        . '"subErrors":[{"message":"Favor preencher o campo linkBotao."}],"codigoErro":1790278898,'
        . '"stackTrace":[{"classLoaderName":"app","moduleVersion":null,"nativeMethod":false}],'
        . '"suppressed":[],"localizedMessage":"Parâmetro(s) de entrada inválido(s)"}';

    function test200ComProtocoloEhEnvio()
    {
        $r = HttpClient::interpretar(200, '{"emailEnviado":true,"protocolo":"77689062768ABC"}');

        $this->assertSame(Result::SENT, $r->outcome);
        $this->assertSame(200, $r->status);
        $this->assertSame('protocolo 77689062768ABC', $r->detail);
    }

    /** O contrato documenta 201, sem corpo garantido. */
    function test201SemCorpoEhEnvio()
    {
        $r = HttpClient::interpretar(201, '');

        $this->assertSame(Result::SENT, $r->outcome);
        $this->assertNull($r->detail);
    }

    /** Recusa, não pendente: a avaliação já existe lá e repetir dá "já enviada". */
    function testEmailNaoEnviadoEhRecusa()
    {
        $r = HttpClient::interpretar(200, '{"emailEnviado":false,"protocolo":"X"}');

        $this->assertSame(Result::REJECTED, $r->outcome);
    }

    /** 3xx é o gateway; o POST não chegou à API. */
    function testRedirecionamentoNaoEhEnvio()
    {
        $r = HttpClient::interpretar(302, '');

        $this->assertSame(Result::RETRY, $r->outcome, 'redirecionamento foi tratado como envio');
        $this->assertSame(302, $r->status);
    }

    function test4xxEhRecusaComOMotivoDoBsc()
    {
        $r = HttpClient::interpretar(400, self::CORPO_RECUSA);

        $this->assertSame(Result::REJECTED, $r->outcome);
        $this->assertSame(400, $r->status);
        $this->assertSame('Parâmetro(s) de entrada inválido(s)', $r->detail);
    }

    function testCredencialRecusadaEhRecusa()
    {
        $this->assertSame(Result::REJECTED, HttpClient::interpretar(401, '')->outcome);
        $this->assertSame(Result::REJECTED, HttpClient::interpretar(403, '')->outcome);
        $this->assertSame(Result::REJECTED, HttpClient::interpretar(404, '')->outcome);
    }

    function testJaEnviadaEhEnvioMesmoCom500()
    {
        $r = HttpClient::interpretar(500, '{"message":"Avaliação já enviada"}');

        $this->assertSame(Result::SENT, $r->outcome);
        $this->assertSame(500, $r->status);
    }

    function test500EhTransitorio()
    {
        $r = HttpClient::interpretar(500, '{"message":"Internal error"}');

        $this->assertSame(Result::RETRY, $r->outcome);
        $this->assertSame('Internal error', $r->detail);
    }

    /** O proxy responde texto puro, e o texto é o motivo. */
    function testProxySemUpstreamEhTransitorioComOTexto()
    {
        $r = HttpClient::interpretar(503, 'no healthy upstream');

        $this->assertSame(Result::RETRY, $r->outcome);
        $this->assertSame('no healthy upstream', $r->detail);
        $this->assertSame('no healthy upstream', $r->body);
    }

    function testSemRespostaEhTransitorio()
    {
        $r = HttpClient::interpretar(0, '');

        $this->assertSame(Result::RETRY, $r->outcome);
        $this->assertNull($r->status);
    }

    /** DNS, conexão e TLS falham antes de qualquer byte sair. */
    function testErroDeRedeAntesDoDespachoVoltaParaAFila()
    {
        foreach ([CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR] as $errno) {
            $r = HttpClient::interpretar(0, '', $errno, 'erro');

            $this->assertSame(Result::RETRY, $r->outcome, "curl errno {$errno} foi tratado como envio");
            $this->assertNull($r->status);
        }
    }

    /** Timeout esperando a resposta é ambíguo: o BSC pode ter gravado. */
    function testErroDeRedeDepoisDoDespachoContaComoEnvio()
    {
        $r = HttpClient::interpretar(0, '', CURLE_OPERATION_TIMEDOUT, 'Operation timed out');

        $this->assertSame(Result::SENT, $r->outcome);
        $this->assertNull($r->status);
        $this->assertStringContainsString('Operation timed out', $r->detail);
    }

    function testCorpoGuardadoNaoTrazAPilhaDeExcecao()
    {
        $r = HttpClient::interpretar(400, self::CORPO_RECUSA);
        $corpo = json_decode($r->body, true);

        $this->assertIsArray($corpo);

        foreach (['stackTrace', 'suppressed', 'localizedMessage'] as $ruido) {
            $this->assertArrayNotHasKey($ruido, $corpo, "{$ruido} sobreviveu à limpeza");
        }

        $this->assertArrayHasKey('subErrors', $corpo, 'o motivo real precisa sobreviver à limpeza');
        $this->assertArrayHasKey('codigoErro', $corpo);
    }

    function testCorpoQueNaoEhObjetoPassaIntacto()
    {
        $this->assertSame('[1,2]', HttpClient::interpretar(500, '[1,2]')->body);
        $this->assertSame('texto', HttpClient::interpretar(500, 'texto')->body);
    }
}
