<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Bsc\Mask;
use GovBrSatisfaction\Bsc\Client;
use GovBrSatisfaction\Bsc\Outcome;
use GovBrSatisfaction\Bsc\Result;
use GovBrSatisfaction\Bsc\Payload;
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
    /** 500 da aplicação antes de recusar. */
    const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Plugin $plugin)
    {
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

        $cpf = Payload::cpf($request->user, $this->plugin->config['metadataFieldCPF']);

        if (!$cpf) {
            $request->sendStatus = SatisfactionRequest::STATUS_NO_CPF;
            $request->save(true);

            return SendOutcome::Done;
        }

        $payload = Payload::build($request, $cpf);

        // Cópia mascarada do corpo, gravada antes do envio.
        try {
            $request->sendPayload = Payload::encode(Mask::forScreen($payload));
        } catch (\JsonException $e) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] corpo da solicitação %d não codifica: %s',
                $request->id,
                Mask::forLogText($e->getMessage())
            ));

            $request->sendStatus = SatisfactionRequest::STATUS_REJECTED;
            $request->sendDetail = 'erro ao montar o corpo: ' . Mask::forLogText($e->getMessage());
            $request->save(true);

            return SendOutcome::Done;
        }

        $request->save(true);

        // Exceção do cliente vira falha da linha.
        $threw = false;

        try {
            $result = $client->send($payload);
        } catch (\Throwable $e) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] o cliente lançou ao enviar a solicitação %d: %s',
                $request->id,
                Mask::forLogText($e->getMessage())
            ));

            $threw = true;
            $result = new Result(Outcome::Retry, null, 'erro no envio: ' . $e->getMessage());
        }

        $request->sendHttpStatus = $result->status;
        $request->sendResponse = $result->body === null ? null : Mask::forBody($result->body);
        $request->sendDetail = $result->detail === null
            ? null
            : mb_substr(Mask::forLogText($result->detail), 0, Result::DETAIL_MAX);

        if ($result->outcome === Outcome::Sent) {
            $request->sendStatus = SatisfactionRequest::STATUS_SENT;
            $request->sendTimestamp = new \DateTime();
            $request->save(true);

            return SendOutcome::Done;
        }

        $giveUp = false;
        $rowFailed = $result->outcome === Outcome::Retry && ($threw || self::countsAsAttempt($result));

        if ($rowFailed) {
            $request->sendAttempts = (int) $request->sendAttempts + 1;
            $giveUp = $request->sendAttempts >= self::MAX_ATTEMPTS;
        }

        if ($giveUp) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] solicitação %d recusada após %d tentativas sem sucesso',
                $request->id,
                $request->sendAttempts
            ));
        }

        $request->sendStatus = $result->outcome === Outcome::Rejected || $giveUp
            ? SatisfactionRequest::STATUS_REJECTED
            : SatisfactionRequest::STATUS_PENDING;

        $request->save(true);

        if ($request->sendStatus === SatisfactionRequest::STATUS_REJECTED) {
            return SendOutcome::Done;
        }

        return $rowFailed ? SendOutcome::RetryRow : SendOutcome::RetryTransport;
    }

    /** Devolve à fila: zera tentativas, mantém a última resposta. */
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
                $delay = $i * SendSatisfactionRequestJob::BULK_INTERVAL;
                SendSatisfactionRequestJob::enqueue($request, 0, $delay > 0 ? "+{$delay} seconds" : 'now');
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

    /** Antecipa a tentativa, sem zerar tentativas. */
    public function retryNow(SatisfactionRequest $request, User $by): void
    {
        App::i()->log->info(sprintf(
            '[GovBrSatisfaction] solicitação %d: tentativa antecipada pelo usuário %d (HTTP %s: %s)',
            $request->id,
            $by->id,
            $request->sendHttpStatus ?? '-',
            Mask::forLogText($request->sendDetail ?? '-')
        ));

        SendSatisfactionRequestJob::enqueue($request);
    }

    /** Só 500 conta como tentativa. */
    private static function countsAsAttempt(Result $result): bool
    {
        return $result->status === 500;
    }
}
