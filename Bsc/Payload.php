<?php

namespace GovBrSatisfaction\Bsc;

use GovBrSatisfaction\Entities\SatisfactionRequest;
use MapasCulturais\Entities\User;

/**
 * Monta o corpo de POST /api/avaliacao/completa.
 *
 * @package GovBrSatisfaction
 */
class Payload
{
    const SISTEMA_SOLICITANTE = 'Mapa da Cultura';

    /** Loopback quando não há requisição. */
    const UNKNOWN_IP = '127.0.0.1';

    /** O contrato pede "dd/mm/aaaa". */
    const DATE_FORMAT = 'd/m/Y';

    /** Codificação única para o fio e a cópia gravada; UTF-8 inválido vira U+FFFD. */
    const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @throws \JsonException
     */
    public static function encode(array $payload): string
    {
        return json_encode($payload, self::JSON_FLAGS);
    }

    public static function build(SatisfactionRequest $request, string $cpf): array
    {
        $user = $request->user;
        $date = $request->dataEtapa->format(self::DATE_FORMAT);

        return [
            'cacheEvict' => false,
            'canalAvaliacao' => $request->canalAvaliacao,
            'canalPrestacao' => $request->canalPrestacao,
            'cpfCidadao' => $cpf,

            'cpfConsulta' => $cpf,

            'dataEtapa' => $date,
            'dataSituacaoEtapa' => $date,
            'email' => (string) $user->email,
            'etapa' => $request->etapa,
            'ipOrigem' => $request->ipOrigem ?: self::UNKNOWN_IP,
            'ipUsuario' => $request->ipUsuario ?: self::UNKNOWN_IP,
            'nomeCidadao' => self::name($user),
            'orgao' => (string) $request->orgao,
            'servico' => $request->servico,
            'sistemaSolicitante' => self::SISTEMA_SOLICITANTE,
            'situacaoEtapa' => $request->situacaoEtapa,
            'usuario' => $cpf,
        ];
    }

    /** CPF do cadastro, só dígitos. */
    public static function cpf(User $user, string $metadataField): ?string
    {
        $agent = $user->profile;

        if (!$agent) {
            return null;
        }

        // `cpf` é a chave antiga do cadastro.
        foreach ([$metadataField, 'cpf'] as $key) {
            // Sem `??`: os metadados não declaram __isset.
            $value = preg_replace('/\D/', '', (string) $agent->$key);

            if (strlen($value) === 11) {
                return $value;
            }
        }

        return null;
    }

    private static function name(User $user): string
    {
        return (string) $user->profile->name;
    }
}
