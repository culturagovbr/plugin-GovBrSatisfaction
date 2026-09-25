<?php

namespace GovBrSatisfaction\Jobs;

use GovBrSatisfaction\Bsc\Mask;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Plugin;
use GovBrSatisfaction\Services\SatisfactionSender;
use GovBrSatisfaction\Services\SendOutcome;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;

/**
 * Envia uma solicitação ao BSC; reagenda a si mesmo quando falha.
 *
 * @package GovBrSatisfaction
 */
class SendSatisfactionRequestJob extends JobType
{
    const SLUG = 'govbr-satisfaction-send';

    /** Espera depois da primeira e da segunda falha. */
    const BACKOFF = ['+1 minutes', '+10 minutes'];

    /** Segundos entre os jobs de um lote. */
    const BULK_INTERVAL = 10;

    /** Um id por solicitação. */
    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return self::SLUG . ':' . ($data['request_id'] ?? '');
    }

    /**
     * Enfileira o envio de uma solicitação, substituindo um job dela se houver.
     *
     * @param int $crashes Execuções seguidas em que o próprio job lançou exceção
     */
    public static function enqueue(SatisfactionRequest $request, string $when = 'now', int $crashes = 0): void
    {
        App::i()->enqueueOrReplaceJob(self::SLUG, [
            'request_id' => $request->id,
            'crashes' => $crashes,
        ], $when);
    }

    /** Espera antes da próxima tentativa, pelo número de falhas. */
    public static function backoff(int $failures): string
    {
        return self::BACKOFF[min(max($failures, 1), count(self::BACKOFF)) - 1];
    }

    protected function _execute(Job $job)
    {
        $app = App::i();
        $plugin = Plugin::instance();

        if (!$plugin || !$plugin->config['enabled']) {
            return true;
        }

        $requestId = (int) ($job->request_id ?? 0);

        // Job sem request_id: distribui um job por pendente.
        if ($requestId === 0) {
            $this->enqueuePending();

            return true;
        }

        $request = $app->repo(SatisfactionRequest::class)->find($requestId);

        if (!$request || $request->sendStatus !== SatisfactionRequest::STATUS_PENDING) {
            return true;
        }

        $app->disableAccessControl();

        try {
            $outcome = $plugin->sender()->send($request, $plugin->client());

            if ($app->em->isOpen()) {
                $app->em->flush();
            }
        } catch (\Throwable $e) {
            $crashes = (int) ($job->crashes ?? 0) + 1;

            $app->log->error(sprintf(
                '[GovBrSatisfaction] falha ao processar a solicitação %d (%d/%d): %s',
                $request->id,
                $crashes,
                SatisfactionSender::MAX_ATTEMPTS,
                Mask::forLogText($e->getMessage())
            ));

            if ($crashes < SatisfactionSender::MAX_ATTEMPTS) {
                self::enqueue($request, self::backoff($crashes), $crashes);
            }

            return true;
        } finally {
            $app->enableAccessControl();
        }

        if ($outcome === SendOutcome::Retry) {
            $when = self::backoff((int) $request->sendAttempts);

            $app->log->warning(sprintf(
                '[GovBrSatisfaction] solicitação %d: tentativa %d/%d falhou; nova tentativa %s',
                $request->id,
                (int) $request->sendAttempts,
                SatisfactionSender::MAX_ATTEMPTS,
                $when
            ));

            self::enqueue($request, $when);
        }

        return true;
    }

    private function enqueuePending(): void
    {
        $pending = App::i()->repo(SatisfactionRequest::class)->findBy([
            'sendStatus' => SatisfactionRequest::STATUS_PENDING,
        ]);

        foreach ($pending as $request) {
            self::enqueue($request);
        }
    }
}
