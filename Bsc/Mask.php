<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Mascara dado pessoal do payload e de texto de log.
 *
 * @package GovBrSatisfaction
 */
class Mask
{
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

    /** Máscara da resposta do BSC: campos pessoais pelo nome, CPF e e-mail no texto. */
    public static function forBody(string $body): string
    {
        $json = json_decode($body, true);

        if (!is_array($json)) {
            return self::forLogText($body);
        }

        $masked = self::walk($json);

        if ($masked === $json) {
            return $body;
        }

        return json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: self::forLogText($body);
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

    /** Mascara CPF (com ou sem formato) e e-mail em texto livre. */
    public static function forLogText(string $text): string
    {
        return preg_replace(
            ['/\d{3}\.\d{3}\.\d{3}-\d{2}/', '/\d{11}/', '/[^\s@"\'<>]+@[^\s@"\'<>]+\.[a-z]{2,}/i'],
            '***',
            $text
        );
    }

    /** Mascara os campos pessoais e o texto livre em qualquer nível. */
    private static function walk(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::walk($value);
            } elseif (is_string($key) && in_array($key, self::PERSONAL_FIELDS, true) && is_scalar($value)) {
                $data[$key] = self::field($key, (string) $value);
            } elseif (is_string($value)) {
                $data[$key] = self::forLogText($value);
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
