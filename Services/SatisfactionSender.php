<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Bsc\Client;
use GovBrSatisfaction\Bsc\Result;
use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Jobs\SendSatisfactionRequestJob;
use GovBrSatisfaction\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Envio de uma solicitação ao BSC. Uma por vez: lote, ordem e adiamento são
 * do job.
 *
 * @package GovBrSatisfaction
 */
class SatisfactionSender
{
    /**
     * Quantos 500 da aplicação antes de desistir. "Já enviada" chega como 500
     * e é reconhecida pelo texto; se a redação mudar, este teto evita laço.
     * Falha de transporte não conta — ver `contaTentativa()`.
     */
    const MAX_ATTEMPTS = 3;

    /** A linha saiu da fila: enviada, recusada, sem CPF ou descartada. */
    const OUTCOME_DONE = 'concluida';

    /** 500 da aplicação para esta linha; a varredura segue para a próxima. */
    const OUTCOME_RETRY_ROW = 'retentar-linha';

    /** Token, rede, gateway ou proxy falharam; a varredura para e se adia. */
    const OUTCOME_RETRY_TRANSPORT = 'retentar-transporte';

    private Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Resolve uma solicitação pendente: descarta, marca sem CPF, ou envia.
     *
     * O cliente vem de fora para que a varredura use um só — o HttpClient
     * guarda o token pela vida da instância.
     *
     * @return string Um dos OUTCOME_*
     */
    public function send(SatisfactionRequest $request, Client $client): string
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

            return self::OUTCOME_DONE;
        }

        $cpf = Payload::cpf($request->user, $this->plugin->config['metadataFieldCPF']);

        if (!$cpf) {
            $request->sendStatus = SatisfactionRequest::STATUS_NO_CPF;
            $request->save(true);

            return self::OUTCOME_DONE;
        }

        $payload = Payload::build($request, $cpf);

        // Gravado antes da chamada: vale mesmo que o envio falhe. Corpo que
        // não codifica não vai sair nunca: recusa, e não retentativa.
        try {
            $request->sendPayload = Payload::encode($payload);
        } catch (\JsonException $e) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] corpo da solicitação %d não codifica: %s',
                $request->id,
                $e->getMessage()
            ));

            $request->sendStatus = SatisfactionRequest::STATUS_REJECTED;
            $request->sendDetail = 'erro ao montar o corpo: ' . $e->getMessage();
            $request->save(true);

            return self::OUTCOME_DONE;
        }

        // Marca antes de chamar: se o processo morrer no meio do POST, é
        // preferível deixar de convidar a convidar duas vezes.
        $request->sendStatus = SatisfactionRequest::STATUS_SENT;
        $request->sendTimestamp = new \DateTime();
        $request->save(true);

        // O cliente devolve Result para tudo. Se lançar, a linha já está
        // marcada como enviada e o job só relê pendentes — vira falha da linha.
        $excecao = false;

        try {
            $resultado = $client->send($payload);
        } catch (\Throwable $e) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] o cliente lançou ao enviar a solicitação %d: %s',
                $request->id,
                $e->getMessage()
            ));

            $excecao = true;
            $resultado = new Result(Result::RETRY, null, 'erro no envio: ' . $e->getMessage());
        }

        $request->sendHttpStatus = $resultado->status;
        $request->sendResponse = $resultado->body;
        $request->sendDetail = $resultado->detail === null
            ? null
            : mb_substr($resultado->detail, 0, Result::DETAIL_MAX);

        if ($resultado->outcome === Result::SENT) {
            $request->save(true);

            return self::OUTCOME_DONE;
        }

        $request->sendTimestamp = null;

        $desistir = false;
        $falhaDaLinha = $resultado->outcome === Result::RETRY && ($excecao || self::contaTentativa($resultado));

        if ($falhaDaLinha) {
            $request->sendAttempts = (int) $request->sendAttempts + 1;
            $desistir = $request->sendAttempts >= self::MAX_ATTEMPTS;
        }

        if ($desistir) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] solicitação %d recusada após %d tentativas sem sucesso',
                $request->id,
                $request->sendAttempts
            ));
        }

        $request->sendStatus = $resultado->outcome === Result::REJECTED || $desistir
            ? SatisfactionRequest::STATUS_REJECTED
            : SatisfactionRequest::STATUS_PENDING;

        $request->save(true);

        if ($request->sendStatus === SatisfactionRequest::STATUS_REJECTED) {
            return self::OUTCOME_DONE;
        }

        return $falhaDaLinha ? self::OUTCOME_RETRY_ROW : self::OUTCOME_RETRY_TRANSPORT;
    }

    /** Devolve à fila: zera tentativas, mantém a última resposta. */
    public function requeue(SatisfactionRequest $request, User $por): void
    {
        $app = App::i();

        $app->log->info(sprintf(
            '[GovBrSatisfaction] solicitação %d devolvida à fila pelo usuário %d (estava %s, HTTP %s: %s)',
            $request->id,
            $por->id,
            $request->sendStatus,
            $request->sendHttpStatus ?? '-',
            $request->sendDetail ?? '-'
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

        // Sem `replace`, como no gatilho: mantém o adiamento se houver.
        $app->enqueueJob(SendSatisfactionRequestJob::SLUG, []);
    }

    /** Só 500 conta como tentativa. */
    private static function contaTentativa(Result $resultado): bool
    {
        return $resultado->status === 500;
    }
}
