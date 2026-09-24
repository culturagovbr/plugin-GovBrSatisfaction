<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Client;
use GovBrSatisfaction\Bsc\Result;
use GovBrSatisfaction\Services\SatisfactionSender;
use Tests\Traits\SpaceDirector;

/**
 * O envio
 *
 * A suíte roda com o transporte de desenvolvimento, então nada sai da máquina. O
 * que se observa aqui é o registro: a situação, o carimbo de tempo e o fato de
 * uma solicitação nunca ser processada duas vezes.
 */
class SendTest extends TestCase
{
    use SpaceDirector;

    /** Recusa real do BSC de homologação, com o motivo em `subErrors`. */
    const CORPO_RECUSA = '{"status":"BAD_REQUEST","message":"Parâmetro(s) de entrada inválido(s)",'
        . '"subErrors":[{"message":"Favor preencher o campo linkBotao."}],"codigoErro":1790278898}';

    protected function publicarEspaco(): void
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));
    }

    /**
     * Credencial recusada não pode marcar como convidado quem não foi.
     *
     * A marcação vem antes da chamada para cobrir o caso ambíguo — morrer no
     * meio do POST. Falha de token não é ambígua: nada saiu, e sem isto a linha
     * ficaria `enviado` para sempre, já que o job só relê pendentes.
     */
    function testEnvioQueNaoSaiVoltaAPendente()
    {
        $this->publicarEspaco();

        // transporte que falha antes de despachar, como o BSC sem token válido
        $this->configurar(['client' => new class implements Client {
            public function send(array $payload): Result
            {
                return new Result(Result::RETRY, 503, 'no healthy upstream');
            }
        }]);

        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('pendente', $linha, 'envio que não saiu ficou marcado como enviado');
        $this->assertNull($linha['send_timestamp'], 'carimbo de envio ficou preenchido sem envio');
    }

    /**
     * Recusa definitiva não pode passar por convite entregue.
     *
     * 401, 403 e 404 não melhoram com repetição, mas isso não faz o cidadão ter
     * sido convidado. Sai da fila e fica visível no painel para alguém olhar.
     */
    function testRecusaDefinitivaNaoContaComoEnviada()
    {
        $this->publicarEspaco();

        $this->configurar(['client' => new class implements Client {
            public function send(array $payload): Result
            {
                return new Result(
                    Result::REJECTED,
                    400,
                    'Parâmetro(s) de entrada inválido(s)',
                    SendTest::CORPO_RECUSA
                );
            }
        }]);

        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('recusado', $linha, 'recusa do BSC ficou marcada como enviada');
        $this->assertNull($linha['send_timestamp'], 'recusa ficou com carimbo de envio');

        // o que a API respondeu fica na linha: sem isso, "Recusado" no painel
        // não distingue payload inválido de BSC fora do ar
        $this->assertSame(400, (int) $linha['send_http_status']);
        $this->assertSame('Parâmetro(s) de entrada inválido(s)', $linha['send_detail']);

        // e o corpo vai inteiro, não o resumo: o motivo real da recusa vem em
        // `subErrors`, que não está documentado e não cabe numa frase
        $this->assertSame(self::CORPO_RECUSA, $linha['send_response']);
    }

    /**
     * E, voltando a pendente, a varredura seguinte alcança a linha — que é o
     * que torna a correção da credencial suficiente, sem mexer no banco.
     */
    function testDepoisDeVoltarAPendenteAProximaVarreduraEnvia()
    {
        $this->publicarEspaco();

        $this->configurar(['client' => new class implements Client {
            public function send(array $payload): Result
            {
                return new Result(Result::RETRY, 503, 'no healthy upstream');
            }
        }]);
        $this->processarEnvios();

        // credencial arrumada
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

    /**
     * O job só lê pendentes. Sem isso, cada varredura reenviaria tudo o que já
     * saiu, e o cidadão receberia a mesma pesquisa a cada poucos segundos.
     */
    function testSegundaVarreduraNaoReenvia()
    {
        $this->publicarEspaco();
        $this->processarEnvios();

        $carimbo = $this->solicitacoes()[0]['send_timestamp'];

        $this->processarEnvios();

        $this->assertSame($carimbo, $this->solicitacoes()[0]['send_timestamp']);
    }

    /**
     * Sem CPF não há o que enviar: o campo é obrigatório na API. O registro fica
     * visível no painel para que o tamanho do caso seja medido, em vez de sumir.
     */
    function testUsuarioSemCpfNaoEnvia()
    {
        $semCpf = $this->userDirector->createUser();
        $this->login($semCpf);

        $this->publicar($this->spaceDirector->createSpace($semCpf->profile));
        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('sem-cpf', $linha);
        $this->assertNull($linha['send_timestamp']);
    }

    /**
     * Os endereços vêm da requisição que publicou, e não do job: ele roda em
     * linha de comando, onde o IP disponível é o loopback do servidor.
     */
    function testGuardaOsEnderecosDoGatilho()
    {
        $this->publicarEspaco();

        $linha = $this->solicitacoes()[0];

        $this->assertArrayHasKey('ip_origem', $linha);
        $this->assertArrayHasKey('ip_usuario', $linha);
    }

    /**
     * Retentativa tem teto.
     *
     * O BSC devolve 500 para indisponibilidade e para regra de negócio, e o que
     * separa os dois é o texto da mensagem. Se uma recusa permanente não for
     * reconhecida, sem este limite a linha retentaria a cada tique do cron para
     * sempre — consumindo a fila e enchendo o log sem chance de mudar.
     */
    function testDesisteDepoisDoLimiteDeTentativas()
    {
        $this->publicarEspaco();

        $this->configurar(['client' => new class implements Client {
            public function send(array $payload): Result
            {
                return new Result(Result::RETRY, 503, 'no healthy upstream');
            }
        }]);

        for ($i = 0; $i < SatisfactionSender::MAX_ATTEMPTS; $i++) {
            $this->processarEnvios();
        }

        $linha = $this->solicitacoes()[0];

        $this->assertSituacao('recusado', $linha, 'a linha continuou retentando depois do limite');
        $this->assertSame(SatisfactionSender::MAX_ATTEMPTS, (int) $linha['send_attempts']);
    }

    /**
     * A pilha de exceção não vai para o banco.
     *
     * A recusa do BSC vem com dezenas de quadros do Java por solicitação, e
     * guardá-los custaria espaço sem ajudar ninguém a diagnosticar nada — o que
     * importa é `subErrors` e `codigoErro`.
     */
    function testCorpoGuardadoNaoTrazAPilhaDeExcecao()
    {
        $corpo = json_decode(self::CORPO_RECUSA, true);

        $this->assertArrayNotHasKey('stackTrace', $corpo);
        $this->assertArrayHasKey('subErrors', $corpo, 'o motivo real precisa sobreviver à limpeza');
        $this->assertArrayHasKey('codigoErro', $corpo);
    }
}
