<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Bsc\Mask;
use GovBrSatisfaction\Bsc\Client;
use GovBrSatisfaction\Bsc\Outcome;
use GovBrSatisfaction\Bsc\Result;
use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Entities\SatisfactionAttempt;
use GovBrSatisfaction\Entities\SatisfactionDispatch;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Jobs\SendSatisfactionRequestJob;
use GovBrSatisfaction\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Envio de uma solicitação ao BSC. Reagendamento é do job.
 *
 * @package GovBrSatisfaction
 */
class SatisfactionSender
{
    /** Falhas antes de recusar. */
    const MAX_ATTEMPTS = 3;

    private readonly DispatchLog $log;

    public function __construct(private readonly Plugin $plugin, ?DispatchLog $log = null)
    {
        $this->log = $log ?? new DispatchLog();
    }

    /** Resolve uma pendente: descarta, marca sem CPF ou envia. */
    public function send(SatisfactionRequest $request, Client $client): SendOutcome
    {
        $app = App::i();

        // Revalida o portal.
        $reason = $this->plugin->subsiteRejectionReason($request->subsite);

        if ($reason) {
            $app->log->warning(sprintf(
                '[GovBrSatisfaction] solicitação %d descartada: %s',
                $request->id,
                $reason
            ));

            $request->delete();

            return SendOutcome::Done;
        }

        $dispatch = $this->log->guard(fn() => $this->log->pending($request)
            ?? $this->log->start($request, SatisfactionDispatch::ORIGIN_REGISTRATION));

        $cpf = Payload::cpf($request->user, $this->plugin->config['metadataFieldCPF']);

        if (!$cpf) {
            $request->sendStatus = SatisfactionRequest::STATUS_NO_CPF;
            $request->save(true);

            $this->close($dispatch, $request->sendStatus);

            return SendOutcome::Done;
        }

        $payload = Payload::build($request, $cpf);
        $number = (int) $request->sendAttempts + 1;
        $startedAt = new \DateTime();

        try {
            Payload::encode($payload);
        } catch (\JsonException $e) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] corpo da solicitação %d não codifica: %s',
                $request->id,
                Mask::forLogText($e->getMessage())
            ));

            $result = new Result(Outcome::Rejected, null, 'erro ao montar o corpo: ' . $e->getMessage());

            $request->sendStatus = SatisfactionRequest::STATUS_REJECTED;
            $request->sendDetail = self::detail($result);
            $request->save(true);

            $this->record($dispatch, $number, $result, null, $startedAt);
            $this->close($dispatch, $request->sendStatus);

            return SendOutcome::Done;
        }

        try {
            $result = $client->send($payload);
        } catch (\Throwable $e) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] o cliente lançou ao enviar a solicitação %d: %s',
                $request->id,
                Mask::forLogText($e->getMessage())
            ));

            $result = new Result(Outcome::Retry, null, 'erro no envio: ' . $e->getMessage());
        }

        $failed = $result->outcome === Outcome::Retry;
        $giveUp = $failed && $number >= self::MAX_ATTEMPTS;

        if ($failed) {
            $request->sendAttempts = $number;
        }

        if ($giveUp) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] solicitação %d recusada após %d tentativas sem sucesso',
                $request->id,
                $number
            ));
        }

        $request->sendHttpStatus = $result->status;
        $request->sendDetail = self::detail($result);
        $request->sendStatus = match (true) {
            $result->outcome === Outcome::Sent => SatisfactionRequest::STATUS_SENT,
            $result->outcome === Outcome::Rejected, $giveUp => SatisfactionRequest::STATUS_REJECTED,
            default => SatisfactionRequest::STATUS_PENDING,
        };

        if ($request->sendStatus === SatisfactionRequest::STATUS_SENT) {
            $request->sendTimestamp = new \DateTime();
        }

        $request->save(true);

        $this->record($dispatch, $number, $result, $payload, $startedAt);

        if ($request->sendStatus === SatisfactionRequest::STATUS_PENDING) {
            return SendOutcome::Retry;
        }

        $this->close($dispatch, $request->sendStatus);

        return SendOutcome::Done;
    }

    /** Devolve à fila: zera tentativas e abre um envio. */
    public function requeue(SatisfactionRequest $request, User $by): void
    {
        $app = App::i();

        $app->log->info(sprintf(
            '[GovBrSatisfaction] solicitação %d devolvida à fila pelo usuário %d (estava %s, HTTP %s: %s)',
            $request->id,
            $by->id,
            $request->sendStatus,
            $request->sendHttpStatus ?? '-',
            Mask::forLogText($request->sendDetail ?? '-')
        ));

        $request->sendStatus = SatisfactionRequest::STATUS_PENDING;
        $request->sendAttempts = 0;
        $request->sendTimestamp = null;

        $app->disableAccessControl();

        try {
            $request->save(true);
        } finally {
            $app->enableAccessControl();
        }

        $this->log->guard(fn() => $this->log->start($request, SatisfactionDispatch::ORIGIN_REQUEUE, $by));

        SendSatisfactionRequestJob::enqueue($request);
    }

    /**
     * Devolve à fila um lote, com jobs escalonados.
     *
     * @param SatisfactionRequest[] $requests
     * @return int Quantas voltaram
     */
    public function requeueMany(array $requests, User $by): int
    {
        if (!$requests) {
            return 0;
        }

        $app = App::i();
        $app->disableAccessControl();

        try {
            foreach (array_values($requests) as $i => $request) {
                $request->sendStatus = SatisfactionRequest::STATUS_PENDING;
                $request->sendAttempts = 0;
                $request->sendTimestamp = null;
                $request->save();
            }

            $app->em->flush();

            foreach (array_values($requests) as $i => $request) {
                $this->log->guard(fn() => $this->log->start($request, SatisfactionDispatch::ORIGIN_BULK, $by));

                $delay = $i * SendSatisfactionRequestJob::BULK_INTERVAL;
                SendSatisfactionRequestJob::enqueue($request, $delay > 0 ? "+{$delay} seconds" : 'now');
            }
        } finally {
            $app->enableAccessControl();
        }

        $ids = array_map(fn(SatisfactionRequest $r) => $r->id, $requests);

        $app->log->info(sprintf(
            '[GovBrSatisfaction] %d solicitações devolvidas à fila pelo usuário %d, espaçadas de %d s (ids %d a %d)',
            count($requests),
            $by->id,
            SendSatisfactionRequestJob::BULK_INTERVAL,
            min($ids),
            max($ids)
        ));

        return count($requests);
    }

    /** Antecipa a tentativa em um envio novo, sem zerar tentativas. */
    public function retryNow(SatisfactionRequest $request, User $by): void
    {
        App::i()->log->info(sprintf(
            '[GovBrSatisfaction] solicitação %d: tentativa antecipada pelo usuário %d (HTTP %s: %s)',
            $request->id,
            $by->id,
            $request->sendHttpStatus ?? '-',
            Mask::forLogText($request->sendDetail ?? '-')
        ));

        $this->log->guard(fn() => $this->log->start($request, SatisfactionDispatch::ORIGIN_RETRY_NOW, $by));

        SendSatisfactionRequestJob::enqueue($request);
    }

    /** Grava a tentativa no envio. */
    private function record(?SatisfactionDispatch $dispatch, int $number, Result $result, ?array $payload, \DateTime $startedAt): void
    {
        if (!$dispatch) {
            return;
        }

        $exchange = $result->exchange;

        $this->log->guard(fn() => $this->log->recordAttempt(
            $dispatch,
            number: $number,
            maxAttempts: self::MAX_ATTEMPTS,
            outcome: $exchange?->simulated ? SatisfactionAttempt::OUTCOME_SIMULATED : $result->outcome->value,
            payload: $payload,
            httpStatus: $result->status,
            response: $result->body,
            detail: $result->detail,
            method: $exchange?->method,
            endpoint: $exchange?->endpoint,
            responseHeaders: $exchange?->responseHeaders,
            durationMs: $exchange?->durationMs,
            sentAt: $exchange?->sentAt ?? $startedAt,
        ));
    }

    /** Encerra o envio na situação da solicitação. */
    private function close(?SatisfactionDispatch $dispatch, string $state): void
    {
        if ($dispatch) {
            $this->log->guard(fn() => $this->log->finish($dispatch, $state));
        }
    }

    /** Resumo mascarado, no tamanho da coluna. */
    private static function detail(Result $result): ?string
    {
        return $result->detail === null
            ? null
            : mb_substr(Mask::forLogText($result->detail), 0, Result::DETAIL_MAX);
    }
}
