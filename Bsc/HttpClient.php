<?php

namespace GovBrSatisfaction\Bsc;

use MapasCulturais\App;

/**
 * Transporte real: POST /api/avaliacao/completa no BSC, autenticado como a
 * consulta de CNPJ (GET no endpoint de token, Bearer na chamada).
 *
 * A leitura da resposta fica em `interpretar()`, pura, para ter teste sem rede.
 *
 * @package GovBrSatisfaction
 */
class HttpClient implements Client
{
    /**
     * Erros de curl que acontecem depois de a requisição ter saído — os únicos
     * ambíguos. DNS, conexão e TLS falham antes de qualquer byte sair.
     */
    const ERROS_APOS_DESPACHO = [
        CURLE_PARTIAL_FILE,
        CURLE_OPERATION_TIMEDOUT,
        CURLE_GOT_NOTHING,
        CURLE_SEND_ERROR,
        CURLE_RECV_ERROR,
    ];

    /** Segundos, para o token e para o POST. */
    const TIMEOUT = 15;

    /** Corte do motivo lido do corpo, para o resumo. */
    const MOTIVO_MAX = 200;

    private string $baseUrl;
    private string $authUrl;
    private string $clientId;
    private string $clientSecret;

    /**
     * Guardado pela vida da instância (uma varredura). A validade não é
     * conhecida: 401 com token reutilizado é tratado como expiração.
     */
    private ?string $token = null;

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

        $reutilizado = $this->token !== null;

        if (!$reutilizado) {
            $this->token = $this->token() ?: null;
        }

        $token = $this->token;

        if (!$token) {
            $app->log->error('[GovBrSatisfaction] envio abortado: não foi possível obter token do BSC');

            return new Result(Result::RETRY, null, 'não foi possível obter token do BSC');
        }

        $resultado = $this->post($payload, $token);

        // Token reutilizado expirado: renova e refaz o POST.
        if ($resultado->status === 401 && $reutilizado) {
            $this->token = $this->token() ?: null;

            $resultado = $this->token
                ? $this->post($payload, $this->token)
                : new Result(Result::RETRY, null, 'não foi possível renovar o token do BSC');
        }

        $this->registrarEmLog($resultado);

        return $resultado;
    }

    private function post(array $payload, string $token): Result
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => "{$this->baseUrl}/api/avaliacao/completa",
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => Payload::encode($payload),
        ]);

        $body = (string) curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return self::interpretar($status, $body, $errno, $error);
    }

    /**
     * Decide o desfecho a partir do que o curl devolveu.
     *
     * @param int    $status    Código HTTP; 0 quando a resposta não veio
     * @param string $body      Corpo cru
     * @param int    $errno     `curl_errno()`; 0 sem erro de rede
     * @param string $curlError `curl_error()`, para o resumo
     */
    public static function interpretar(int $status, string $body, int $errno = 0, string $curlError = ''): Result
    {
        if ($errno !== 0) {
            // Após o despacho é ambíguo: melhor deixar de convidar que convidar duas vezes.
            if (in_array($errno, self::ERROS_APOS_DESPACHO, true)) {
                return new Result(Result::SENT, null, "falha de rede após o despacho: {$curlError}");
            }

            return new Result(Result::RETRY, null, "falha de rede antes do despacho: {$curlError}");
        }

        $body = self::limpar($body);

        // Só 2xx é a API respondendo (200 e 201 documentados); 3xx é o gateway.
        if ($status >= 200 && $status < 300) {
            $json = json_decode($body, true);

            // `emailEnviado` falso é o BSC dizendo que não convidou. Recusado,
            // não pendente: a avaliação já existe lá e repetir dá "já enviada".
            if (is_array($json) && array_key_exists('emailEnviado', $json) && $json['emailEnviado'] === false) {
                return new Result(Result::REJECTED, $status, 'o BSC informou que o e-mail não foi enviado', $body);
            }

            $protocolo = is_array($json) ? ($json['protocolo'] ?? null) : null;

            return new Result(Result::SENT, $status, $protocolo ? "protocolo {$protocolo}" : null, $body);
        }

        $motivo = self::motivo($body);

        // "Já enviada": enviado.
        if ($motivo && mb_stripos($motivo, 'já enviada') !== false) {
            return new Result(Result::SENT, $status, $motivo, $body);
        }

        // 4xx: credencial, permissão, serviço inexistente, payload inválido.
        if ($status >= 400 && $status < 500) {
            return new Result(Result::REJECTED, $status, $motivo, $body);
        }

        // 5xx, 3xx e 0 sem erro de curl: transitório.
        $detalhe = $motivo ?? ($status ? "resposta inesperada HTTP {$status}" : 'sem resposta do BSC');

        return new Result(Result::RETRY, $status ?: null, $detalhe, $body);
    }

    /**
     * Envio limpo não loga. Enviado por outro caminho ("já enviada", rede
     * após o despacho) é aviso; o resto é erro.
     */
    private function registrarEmLog(Result $resultado): void
    {
        $limpo = $resultado->outcome === Result::SENT
            && $resultado->status !== null && $resultado->status < 300;

        if ($limpo) {
            return;
        }

        $mensagem = sprintf(
            '[GovBrSatisfaction] envio ao BSC: %s, HTTP %s%s',
            $resultado->outcome,
            $resultado->status ?? '-',
            $resultado->detail ? " — {$resultado->detail}" : ''
        );

        if ($resultado->outcome === Result::SENT) {
            App::i()->log->warning($mensagem);
        } else {
            App::i()->log->error($mensagem);
        }
    }

    /** Remove a pilha de exceção do corpo; JSON que não é objeto passa intacto. */
    private static function limpar(string $body): string
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

    private static function motivo(string $body): ?string
    {
        $json = json_decode($body, true);

        if (is_array($json)) {
            foreach (['detail', 'message', 'title'] as $chave) {
                if (isset($json[$chave]) && is_string($json[$chave]) && $json[$chave] !== '') {
                    return mb_substr($json[$chave], 0, self::MOTIVO_MAX);
                }
            }

            return null;
        }

        $body = trim($body);

        return $body === '' ? null : mb_substr($body, 0, self::MOTIVO_MAX);
    }

    private function token(): ?string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $this->authUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => [
                'Content-type: application/json',
                "clientId: {$this->clientId}",
                "clientSecret: {$this->clientSecret}",
            ],
        ]);

        $result = (string) curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error) {
            App::i()->log->warning("[GovBrSatisfaction] falha ao autenticar no BSC: {$error}");
            return null;
        }

        $response = json_decode($result, true);
        $token = is_array($response) ? ($response['accessToken'] ?? null) : null;

        if (!$token) {
            App::i()->log->warning(sprintf(
                '[GovBrSatisfaction] o endpoint de token respondeu HTTP %d sem accessToken: %s',
                $status,
                mb_substr(trim($result), 0, self::MOTIVO_MAX)
            ));
        }

        return $token ?: null;
    }
}
