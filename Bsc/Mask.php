<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Mascara dado pessoal do payload e de texto de log.
 *
 * @package GovBrSatisfaction
 */
class Mask
{
    /** Nome de campo ou cabeçalho que carrega credencial. */
    const CREDENTIAL = '/(cookie|authorization|token|secret|password|senha|session|api[-_]?key)/i';

    /** Campos do payload que identificam a pessoa. */
    const PERSONAL_FIELDS = ['cpfCidadao', 'cpfConsulta', 'usuario', 'email', 'nomeCidadao', 'ipOrigem', 'ipUsuario'];

    /** Máscara parcial, para a tela. */
    public static function forScreen(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array($key, self::PERSONAL_FIELDS, true) && is_scalar($value)) {
                $payload[$key] = self::field($key, (string) $value);
            }
        }

        return $payload;
    }

    /**
     * Máscara da resposta do BSC: campos pessoais pelo nome e dado pessoal no texto.
     *
     * @param string[] $known Valores reais do envio
     */
    public static function forBody(string $body, array $known = []): string
    {
        $json = json_decode($body, true);

        if (!is_array($json)) {
            return self::forLogText($body, $known);
        }

        $masked = self::walk($json, $known);

        if ($masked === $json) {
            return $body;
        }

        return json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: self::forLogText($body, $known);
    }

    /** Valores pessoais preenchidos do payload real. */
    public static function personalValues(array $payload): array
    {
        $values = [];

        foreach (self::PERSONAL_FIELDS as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                $values[] = trim((string) $payload[$key]);
            }
        }

        return array_values(array_unique($values));
    }

    /** Máscara total, para o log. */
    public static function forLog(array $payload): array
    {
        foreach (self::PERSONAL_FIELDS as $key) {
            if (!empty($payload[$key])) {
                $payload[$key] = '***';
            }
        }

        return $payload;
    }

    /**
     * Mascara em texto livre CPF, e-mail, IP, token Bearer e os valores conhecidos.
     *
     * @param string[] $known Valores reais do envio
     */
    public static function forLogText(string $text, array $known = []): string
    {
        $text = preg_replace(
            [
                '/(?<!\d)\d{3}[.\s]?\d{3}[.\s]?\d{3}[-.\s]?\d{2}(?!\d)/',
                '/[^\s@"\'<>]+@[^\s@"\'<>]+\.[a-z]{2,}/i',
                '/Bearer\s+(?!\*\*\*)[^\s"\'<>]+/i',
            ],
            ['***', '***', 'Bearer ***'],
            self::known($text, $known)
        );

        return self::ips($text);
    }

    /** Troca por *** cada ocorrência dos valores, do mais longo ao mais curto. */
    private static function known(string $text, array $values): string
    {
        usort($values, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach ($values as $value) {
            if (mb_strlen($value) >= 4) {
                $text = str_ireplace($value, '***', $text);
            }
        }

        return $text;
    }

    /** Troca por *** os IPv4 e os IPv6 válidos com dois blocos ou mais e algum dígito. */
    private static function ips(string $text): string
    {
        $text = preg_replace_callback(
            '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/',
            fn(array $match) => filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? '***' : $match[0],
            $text
        );

        return preg_replace_callback(
            '/(?<![0-9a-f:])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![0-9a-f:])/i',
            fn(array $match) => filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                && count(array_filter(explode(':', $match[0]), 'strlen')) >= 2
                && preg_match('/\d/', $match[0])
                ? '***'
                : $match[0],
            $text
        );
    }

    /** Cabeçalhos da resposta sem os de cookie e credencial, com o texto mascarado. */
    public static function headers(array $lines, array $known = []): array
    {
        $kept = array_filter($lines, function ($line) {
            $name = strstr((string) $line, ':', true);

            return $name === false || !preg_match(self::CREDENTIAL, $name);
        });

        return array_values(array_map(fn($line) => self::forLogText((string) $line, $known), $kept));
    }

    /** Mascara credenciais, campos pessoais, texto livre e números de 11 dígitos em qualquer nível. */
    private static function walk(array $data, array $known): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::CREDENTIAL, $key) && $value !== null && !is_array($value)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::walk($value, $known);
            } elseif (is_string($key) && in_array($key, self::PERSONAL_FIELDS, true) && is_scalar($value)) {
                $data[$key] = self::field($key, (string) $value);
            } elseif (is_string($value)) {
                $data[$key] = self::forLogText($value, $known);
            } elseif (is_int($value) && preg_match('/^\d{11}$/', (string) $value)) {
                $data[$key] = '***';
            }
        }

        return $data;
    }

    /** Máscara parcial de um campo pessoal, pelo nome. */
    private static function field(string $key, string $value): string
    {
        return match ($key) {
            'email' => self::email($value),
            'nomeCidadao' => self::name($value),
            'ipOrigem', 'ipUsuario' => self::ip($value),
            default => self::cpf($value),
        };
    }

    /** Idempotente. */
    public static function cpf(string $cpf): string
    {
        if (preg_match('/^\d{3}\.\*{3}\.\*{3}-\d{2}$/', $cpf) || $cpf === '***') {
            return $cpf;
        }

        if (strlen($cpf) !== 11) {
            return '***';
        }

        return substr($cpf, 0, 3) . '.***.***-' . substr($cpf, -2);
    }

    public static function email(string $email): string
    {
        [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($user, 0, 1) . '***' . ($domain ? '@' . $domain : '');
    }

    public static function name(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));

        return $parts[0] . (count($parts) > 1 ? ' ***' : '');
    }

    /** Mantém os dois primeiros blocos do IPv4 ou do IPv6. Idempotente. */
    public static function ip(string $ip): string
    {
        if (str_contains($ip, '***')) {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            [$first, $second] = explode('.', $ip);

            return "{$first}.{$second}.***.***";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            [$first, $second] = explode(':', $ip);

            return "{$first}:{$second}:***";
        }

        return '***';
    }
}
