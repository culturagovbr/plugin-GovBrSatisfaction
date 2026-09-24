<?php

namespace GovBrSatisfaction\Bsc;

use GovBrSatisfaction\Entities\SatisfactionRequest;
use MapasCulturais\Entities\User;

/**
 * Monta o corpo de POST /api/avaliacao/completa a partir do registro local.
 *
 * Os dados pessoais são lidos do usuário na hora do envio, não de cópia na
 * tabela — ver GovBrSatisfaction\Entities\SatisfactionRequest.
 *
 * `/completa` não recebe `protocolo`, diferente dos outros endpoints: quem o
 * gera é o BSC.
 *
 * @package GovBrSatisfaction
 */
class Payload
{
    const SISTEMA_SOLICITANTE = 'Mapa da Cultura';

    /**
     * O gov.br espera as datas neste formato, não em ISO.
     */
    const DATE_FORMAT = 'd/m/Y';

    public static function build(SatisfactionRequest $request, string $cpf): array
    {
        $user = $request->user;
        $data = $request->dataEtapa->format(self::DATE_FORMAT);

        return [
            'cacheEvict' => false,
            'canalAvaliacao' => $request->canalAvaliacao,
            'canalPrestacao' => $request->canalPrestacao,
            'cpfCidadao' => $cpf,

            // Opcional no contrato, e igual ao do cidadão: quem concluiu o
            // serviço é a mesma pessoa que a avaliação consulta.
            'cpfConsulta' => $cpf,

            'dataEtapa' => $data,
            'dataSituacaoEtapa' => $data,
            'email' => (string) $user->email,
            'etapa' => $request->etapa,
            'ipOrigem' => $request->ipOrigem ?: '127.0.0.1',
            'ipUsuario' => $request->ipUsuario ?: '127.0.0.1',
            'nomeCidadao' => self::nome($user),
            'orgao' => (string) $request->orgao,
            'servico' => $request->servico,
            'sistemaSolicitante' => self::SISTEMA_SOLICITANTE,
            'situacaoEtapa' => $request->situacaoEtapa,
            // O contrato aceita "login, cpf ou identificador". Vai o CPF: é o
            // que identifica a pessoa do lado do gov.br, enquanto o e-mail e o
            // id interno do Mapa não são reconhecidos lá.
            'usuario' => $cpf,
        ];
    }

    /**
     * Só os dígitos: o cadastro local grava o CPF com máscara, e o login
     * gov.br grava sem.
     */
    public static function cpf(User $user, string $metadataField): ?string
    {
        $agent = $user->profile;

        if (!$agent) {
            return null;
        }

        // `documento` é a chave configurada na instalação; `cpf` é a que
        // versões antigas do cadastro gravaram. MultipleLocalAuth consulta as
        // duas pelo mesmo motivo.
        foreach ([$metadataField, 'cpf'] as $key) {
            // Sem `??` de propósito: as entidades do Mapas resolvem metadados
            // no __get mas não declaram __isset, e o operador de coalescência
            // consulta isset() primeiro — devolveria nulo mesmo com o metadado
            // gravado no banco.
            $value = preg_replace('/\D/', '', (string) $agent->$key);

            if (strlen($value) === 11) {
                return $value;
            }
        }

        return null;
    }

    private static function nome(User $user): string
    {
        $agent = $user->profile;

        return (string) ($agent ? $agent->name : $user->email);
    }
}
