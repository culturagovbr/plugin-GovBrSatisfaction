<?php

namespace GovBrSatisfaction\Jobs;

use GovBrSatisfaction\Bsc\Mask;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Plugin;
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

    /** Espera entre tentativas de transporte, por falhas seguidas. */
    const BACKOFF = ['+1 minutes', '+10 minutes', '+30 minutes'];

    /** Espera após 500 da aplicação. */
    const ROW_RETRY = '+1 minutes';

    /** Segundos entre os jobs de um lote. */
    const BULK_INTERVAL = 10;

    /** Um id por solicitação. */
    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return self::SLUG . ':' . ($data['request_id'] ?? '');
    }

    /**
     * Enfileira o envio de uma solicitação, substituindo um job dela se houver.
     */
    public static function enqueue(SatisfactionRequest $request, int $failures = 0, string $when = 'now'): void
    {
        App::i()->enqueueOrReplaceJob(self::SLUG, [
            'request_id' => $request->id,
            'failures' => $failures,
        ], $when);
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

        $failures = (int) ($job->failures ?? 0);

        $app->disableAccessControl();

        try {
            $outcome = $plugin->sender()->send($request, $plugin->client());

            if ($app->em->isOpen()) {
                $app->em->flush();
            }
        } catch (\Throwable $e) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] falha ao processar a solicitação %d: %s',
                $request->id,
                Mask::forLogText($e->getMessage())
            ));

            // Exceção: trata como falha de transporte.
            $outcome = SendOutcome::RetryTransport;
        } finally {
            $app->enableAccessControl();
        }

        if ($outcome === SendOutcome::RetryTransport) {
            $failures++;
            $when = self::BACKOFF[min($failures - 1, count(self::BACKOFF) - 1)];

            $app->log->warning(sprintf(
                '[GovBrSatisfaction] solicitação %d: transporte indisponível; nova tentativa %s',
                $request->id,
                $when
            ));

            self::enqueue($request, $failures, $when);
        } elseif ($outcome === SendOutcome::RetryRow) {
            self::enqueue($request, 0, self::ROW_RETRY);
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
