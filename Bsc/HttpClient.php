<?php

namespace GovBrSatisfaction\Bsc;

use MapasCulturais\App;

/**
 * Transporte real: POST /api/avaliacao/completa no BSC.
 *
 * Autentica como a consulta de CNPJ já faz — GET no endpoint de token com
 * `clientId`/`clientSecret` em cabeçalho, Bearer na chamada —, por isso reusa
 * as credenciais `RCV_BSC_*`.
 *
 * O corpo da resposta não é lido, por definição da API. O log registra só o que
 * este cliente não conseguiu fazer, para que falha de infraestrutura apareça.
 *
 * @package GovBrSatisfaction
 */
class HttpClient implements Client
{
    private string $baseUrl;
    private string $authUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct(string $baseUrl, string $authUrl, string $clientId, string $clientSecret)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authUrl = $authUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }

    public function send(array $payload): Result
    {
        $app = App::i();

        $token = $this->token();

        if (!$token) {
            // Nada foi despachado, e isso é certo: o `false` devolve a linha para
            // pendente, e a próxima varredura tenta de novo. Credencial errada
            // não pode marcar como convidado quem não foi.
            $app->log->error('[GovBrSatisfaction] envio abortado: não foi possível obter token do BSC');

            return new Result(Result::RETRY, null, 'não foi possível obter token do BSC');
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => "{$this->baseUrl}/api/avaliacao/completa",
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $body = $this->limpar((string) curl_exec($ch));
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        // `true` mesmo com erro de rede: aqui a requisição já saiu, e a falha pode
        // ter acontecido depois de o BSC recebê-la. É o caso ambíguo, em que se
        // escolhe deixar de convidar em vez de convidar duas vezes.
        if ($error) {
            $app->log->error("[GovBrSatisfaction] falha de rede no envio ao BSC: {$error}");

            return new Result(Result::SENT, null, "falha de rede após o despacho: {$error}");
        }

        if ($status < 400) {
            // `emailEnviado` é, pelo contrato, o "indicador de e-mail enviado
            // pelo BSC". Falso é o BSC dizendo que não enviou, e a situação da
            // linha existe justamente para responder quem foi convidado — tratar
            // isso como envio seria afirmar o contrário do que a resposta diz.
            //
            // Recusado, e não pendente: a avaliação já foi criada do lado deles,
            // então repetir devolve "já enviada" e nada muda. É caso para
            // alguém olhar no painel.
            $json = json_decode($body, true);

            if (is_array($json) && array_key_exists('emailEnviado', $json) && $json['emailEnviado'] === false) {
                $app->log->error(sprintf(
                    '[GovBrSatisfaction] o BSC aceitou com HTTP %d mas informou que o e-mail não foi enviado',
                    $status
                ));

                return new Result(Result::REJECTED, $status, 'o BSC informou que o e-mail não foi enviado', $body);
            }

            // O protocolo é o identificador do registro do lado deles, e é o que
            // permite rastrear um caso junto ao BSC sem depender do nosso log.
            $protocolo = is_array($json) ? ($json['protocolo'] ?? null) : null;

            return new Result(Result::SENT, $status, $protocolo ? "protocolo {$protocolo}" : null, $body);
        }

        $motivo = $this->motivo($body);

        $app->log->error(sprintf(
            '[GovBrSatisfaction] o BSC recusou o envio com HTTP %d%s',
            $status,
            $motivo ? ": {$motivo}" : ''
        ));

        // O BSC devolve 500 tanto para falha de infraestrutura quanto para regra
        // de negócio: "Avaliação já enviada" chega como 500. O código sozinho,
        // portanto, não diz se repetir adianta — daí a leitura do motivo, que é
        // para decidir o desfecho e não para guardar o retorno.
        //
        // Já enviada é o estado desejado alcançado: o cidadão foi convidado, seja
        // por uma tentativa anterior cujo erro veio da auditoria depois da
        // gravação, seja por outro caminho. Retentar seria laço infinito.
        if ($motivo && mb_stripos($motivo, 'já enviada') !== false) {
            return new Result(Result::SENT, $status, $motivo, $body);
        }

        // 4xx é recusa definitiva — credencial (401), permissão (403), serviço ou
        // órgão inexistente (404), payload inválido (400). Repetir não muda
        // nenhum deles, e marcar como enviado diria que o cidadão foi convidado
        // quando ninguém foi: é recusa, e o painel precisa mostrar isso.
        if ($status < 500) {
            return new Result(Result::REJECTED, $status, $motivo, $body);
        }

        // 5xx restante é falha do servidor deles, transitória por natureza.
        return new Result(Result::RETRY, $status, $motivo, $body);
    }

    /**
     * Motivo da recusa, como o BSC o descreve.
     *
     * O corpo só é lido quando a resposta é de erro, e apenas para o log e para
     * a decisão de retentar. Nada dele é guardado.
     */
    /**
     * Tira do corpo o que não serve para diagnosticar.
     *
     * A resposta de erro do BSC vem com a pilha de exceção do Java inteira —
     * dezenas de quadros com `classLoaderName`, `moduleVersion` e `nativeMethod`
     * —, dezenas de milhares de caracteres por solicitação. O que diagnostica
     * são `detail`, `subErrors` e `codigoErro`, que ficam soterrados.
     *
     * Limpar aqui, e não só na exibição, evita carregar esse peso no banco a
     * cada recusa. Corpo que não é JSON passa intacto: quando o proxy responde
     * "no healthy upstream", o texto já é a informação.
     */
    private function limpar(string $body): string
    {
        $json = json_decode($body, true);

        if (!is_array($json) || array_is_list($json)) {
            return $body;
        }

        foreach (['stackTrace', 'suppressed', 'cause', 'localizedMessage', 'instance', 'type'] as $chave) {
            unset($json[$chave]);
        }

        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $body;
    }

    private function motivo(string $body): ?string
    {
        $json = json_decode($body, true);

        if (is_array($json)) {
            foreach (['detail', 'message', 'title'] as $chave) {
                if (isset($json[$chave]) && is_string($json[$chave]) && $json[$chave] !== '') {
                    return mb_substr($json[$chave], 0, 200);
                }
            }

            return null;
        }

        // O proxy responde em texto puro quando não há instância de pé
        // ("no healthy upstream"), e nesse caso o corpo já é a mensagem.
        $body = trim($body);

        return $body === '' ? null : mb_substr($body, 0, 200);
    }

    /**
     * @return string|null Token de acesso, ou null quando o BSC não respondeu
     */
    private function token(): ?string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Content-type: application/json',
                "clientId: {$this->clientId}",
                "clientSecret: {$this->clientSecret}",
            ],
        ]);

        $result = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            App::i()->log->warning("[GovBrSatisfaction] falha ao autenticar no BSC: {$error}");
            return null;
        }

        $response = json_decode($result, true);

        return $response['accessToken'] ?? null;
    }
}
