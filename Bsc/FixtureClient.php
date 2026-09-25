<?php

namespace GovBrSatisfaction\Bsc;

use MapasCulturais\App;

/**
 * Transporte de desenvolvimento: só loga, nada sai da máquina.
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
            Payload::encode(Mascara::paraLog($payload))
        ));

        return new Result(
            Outcome::Sent,
            200,
            'fixture: nenhuma requisição HTTP foi feita',
            json_encode(['emailEnviado' => true, 'protocolo' => 'FIXTURE'], JSON_UNESCAPED_UNICODE)
        );
    }
}
