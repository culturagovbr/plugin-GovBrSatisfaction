<?php

namespace GovBrSatisfaction\Bsc;

use MapasCulturais\App;

/**
 * Transporte real: token no gateway e POST /api/avaliacao/completa.
 *
 * @package GovBrSatisfaction
 */
class HttpClient implements Client
{
    /** Segundos, para o token e para o POST. */
    const TIMEOUT = 15;

    /** Corte do motivo lido do corpo, para o resumo. */
    const REASON_MAX = 200;

    private readonly string $baseUrl;

    /** Token da instância; 401 com token reutilizado renova uma vez. */
    private ?string $token = null;

    public function __construct(
        string $baseUrl,
        private readonly string $authUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function send(array $payload): Result
    {
        $app = App::i();

        $reused = $this->token !== null;

        if (!$reused) {
            $this->token = $this->token() ?: null;
        }

        $token = $this->token;

        if (!$token) {
            $app->log->error('[GovBrSatisfaction] envio abortado: não foi possível obter token do BSC');

            return new Result(Outcome::Retry, null, 'não foi possível obter token do BSC');
        }

        $result = $this->post($payload, $token);

        // Token reutilizado expirado: renova e refaz o POST.
        if ($result->status === 401 && $reused) {
            $this->token = $this->token() ?: null;

            $result = $this->token
                ? $this->post($payload, $this->token)
                : new Result(Outcome::Retry, null, 'não foi possível renovar o token do BSC');
        }

        $this->log($result);

        return $result;
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

        return self::interpret($status, $body, $errno, $error);
    }

    /**
     * Decide o desfecho a partir do que o curl devolveu.
     *
     * @param int    $status    Código HTTP; 0 quando a resposta não veio
     * @param string $body      Corpo cru
     * @param int    $errno     `curl_errno()`; 0 sem erro de rede
     * @param string $curlError `curl_error()`, para o resumo
     */
    public static function interpret(int $status, string $body, int $errno = 0, string $curlError = ''): Result
    {
        // Erro de rede: retentar.
        if ($errno !== 0) {
            return new Result(Outcome::Retry, null, "falha de rede: {$curlError}");
        }

        $body = self::strip($body);

        // 2xx: enviado; emailEnviado=false vai para o detalhe.
        if ($status >= 200 && $status < 300) {
            $json = json_decode($body, true);
            $protocol = is_array($json) ? ($json['protocolo'] ?? null) : null;
            $emailPending = is_array($json) && array_key_exists('emailEnviado', $json) && $json['emailEnviado'] === false;

            $detail = trim(($protocol ? "protocolo {$protocol}" : '') . ($emailPending ? ' (e-mail pendente no BSC)' : ''));

            return new Result(Outcome::Sent, $status, $detail !== '' ? $detail : null, $body);
        }

        $reason = self::reason($body);

        // "Já enviada": enviado.
        if ($reason && mb_stripos($reason, 'já enviada') !== false) {
            return new Result(Outcome::Sent, $status, $reason, $body);
        }

        // 4xx: credencial, permissão, serviço inexistente, payload inválido.
        if ($status >= 400 && $status < 500) {
            return new Result(Outcome::Rejected, $status, $reason, $body);
        }

        // 5xx, 3xx e 0 sem erro de curl: transitório.
        $detail = $reason ?? ($status ? "resposta inesperada HTTP {$status}" : 'sem resposta do BSC');

        return new Result(Outcome::Retry, $status ?: null, $detail, $body);
    }

    /** Loga o resultado; envio limpo não loga. */
    private function log(Result $result): void
    {
        $clean = $result->outcome === Outcome::Sent
            && $result->status !== null && $result->status < 300;

        if ($clean) {
            return;
        }

        $message = sprintf(
            '[GovBrSatisfaction] envio ao BSC: %s, HTTP %s%s',
            $result->outcome->value,
            $result->status ?? '-',
            $result->detail ? " — " . Mask::forLogText($result->detail) : ''
        );

        if ($result->outcome === Outcome::Sent) {
            App::i()->log->warning($message);
        } else {
            App::i()->log->error($message);
        }
    }

    /** Remove a pilha de exceção do corpo; JSON que não é objeto passa intacto. */
    private static function strip(string $body): string
    {
        $json = json_decode($body, true);

        if (!is_array($json) || array_is_list($json)) {
            return $body;
        }

        foreach (['stackTrace', 'suppressed', 'cause', 'localizedMessage', 'instance', 'type'] as $key) {
            unset($json[$key]);
        }

        return json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $body;
    }

    private static function reason(string $body): ?string
    {
        $json = json_decode($body, true);

        if (is_array($json)) {
            foreach (['detail', 'message', 'title'] as $key) {
                if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                    return mb_substr($json[$key], 0, self::REASON_MAX);
                }
            }

            return null;
        }

        $body = trim($body);

        return $body === '' ? null : mb_substr($body, 0, self::REASON_MAX);
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
                Mask::forLogText(mb_substr(trim($result), 0, self::REASON_MAX))
            ));
        }

        return $token ?: null;
    }
}
