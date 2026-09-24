<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Mascara dado pessoal do payload e de texto de log.
 *
 * @package GovBrSatisfaction
 */
class Mascara
{
    /** Campos do payload que identificam a pessoa. */
    const CAMPOS_PESSOAIS = ['cpfCidadao', 'cpfConsulta', 'usuario', 'email', 'nomeCidadao'];

    /** Máscara parcial, para a tela. */
    public static function paraTela(array $payload): array
    {
        foreach (['cpfCidadao', 'cpfConsulta', 'usuario'] as $chave) {
            if (isset($payload[$chave])) {
                $payload[$chave] = self::cpf((string) $payload[$chave]);
            }
        }

        if (isset($payload['email'])) {
            $payload['email'] = self::email((string) $payload['email']);
        }

        if (isset($payload['nomeCidadao'])) {
            $payload['nomeCidadao'] = self::nome((string) $payload['nomeCidadao']);
        }

        return $payload;
    }

    /** Máscara total, para o log. */
    public static function paraLog(array $payload): array
    {
        foreach (self::CAMPOS_PESSOAIS as $chave) {
            if (!empty($payload[$chave])) {
                $payload[$chave] = '***';
            }
        }

        return $payload;
    }

    public static function cpf(string $cpf): string
    {
        if (strlen($cpf) !== 11) {
            return '***';
        }

        return substr($cpf, 0, 3) . '.***.***-' . substr($cpf, -2);
    }

    public static function email(string $email): string
    {
        [$usuario, $dominio] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($usuario, 0, 1) . '***' . ($dominio ? '@' . $dominio : '');
    }

    public static function nome(string $nome): string
    {
        $partes = preg_split('/\s+/', trim($nome));

        return $partes[0] . (count($partes) > 1 ? ' ***' : '');
    }
}
