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
    const PERSONAL_FIELDS = ['cpfCidadao', 'cpfConsulta', 'usuario', 'email', 'nomeCidadao'];

    /** Máscara parcial, para a tela. */
    public static function forScreen(array $payload): array
    {
        foreach (['cpfCidadao', 'cpfConsulta', 'usuario'] as $key) {
            if (isset($payload[$key])) {
                $payload[$key] = self::cpf((string) $payload[$key]);
            }
        }

        if (isset($payload['email'])) {
            $payload['email'] = self::email((string) $payload['email']);
        }

        if (isset($payload['nomeCidadao'])) {
            $payload['nomeCidadao'] = self::name((string) $payload['nomeCidadao']);
        }

        return $payload;
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
}
