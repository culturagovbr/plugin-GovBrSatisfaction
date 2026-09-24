<?php

namespace GovBrSatisfaction\Bsc;

use MapasCulturais\App;

/**
 * Transporte de desenvolvimento: não sai da máquina.
 *
 * O payload carrega CPF, nome e e-mail reais: disparar de desenvolvimento não
 * seria ruído no gov.br, seria vazamento. Aqui só vai para o log.
 *
 * É o padrão — sem `AVALIACAO_DEV_MODE` definido, roda esta. A falha segura é
 * não enviar.
 *
 * @package GovBrSatisfaction
 */
class FixtureClient implements Client
{
    public function send(array $payload): Result
    {
        $app = App::i();

        $app->log->info(sprintf(
            '[GovBrSatisfaction] fixture: envio simulado para o serviço %s (nenhuma requisição HTTP foi feita) %s',
            $payload['servico'] ?? '?',
            json_encode($this->mask($payload), JSON_UNESCAPED_UNICODE)
        ));

        return new Result(
            Result::SENT,
            200,
            'fixture: nenhuma requisição HTTP foi feita',
            json_encode(['emailEnviado' => true, 'protocolo' => 'FIXTURE'], JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * O log da aplicação é lido por mais gente do que o banco. O payload vai
     * para lá só como conferência de montagem, então CPF e e-mail aparecem
     * encobertos.
     */
    private function mask(array $payload): array
    {
        foreach (['cpfCidadao', 'cpfConsulta', 'email', 'usuario', 'nomeCidadao'] as $field) {
            if (!empty($payload[$field])) {
                $payload[$field] = '***';
            }
        }

        return $payload;
    }
}
