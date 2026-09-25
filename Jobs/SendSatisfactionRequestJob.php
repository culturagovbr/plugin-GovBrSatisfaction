<?php

namespace GovBrSatisfaction\Jobs;

use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Plugin;
use GovBrSatisfaction\Services\SendOutcome;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;

/**
 * Varredura das solicitações pendentes. Aqui ficam lote, ordem e adiamento;
 * cada envio é do Services\SatisfactionSender.
 *
 * @package GovBrSatisfaction
 */
class SendSatisfactionRequestJob extends JobType
{
    const SLUG = 'govbr-satisfaction-send';

    /** Por execução; uma fila represada escoa em várias. */
    const BATCH_SIZE = 50;

    /**
     * Espera antes da próxima varredura quando o transporte falha, indexada
     * pela quantidade de falhas seguidas; da última posição em diante mantém.
     */
    const BACKOFF = ['now', '+1 minutes', '+10 minutes', '+1 hours'];

    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return self::SLUG;
    }

    protected function _execute(Job $job)
    {
        $app = App::i();
        $plugin = Plugin::instance();

        // Desligado não faz nada, mas o tipo continua registrado para que uma
        // execução já enfileirada não quebre.
        if (!$plugin || !$plugin->config['enabled']) {
            return true;
        }

        // O core não desabilita sozinho (linha comentada em App::executeJob()).
        // O finally é obrigatório: o contador vazaria para os jobs seguintes.
        $app->disableAccessControl();

        $retry = false;
        $retryRows = false;
        $failures = (int) ($job->failures ?? 0);

        // Um cliente para a varredura inteira: o HttpClient guarda o token.
        $client = $plugin->client();
        $sender = $plugin->sender();

        try {
            $pending = $app->repo(SatisfactionRequest::class)->findBy(
                ['sendStatus' => SatisfactionRequest::STATUS_PENDING],
                ['createTimestamp' => 'ASC'],
                self::BATCH_SIZE
            );

            foreach ($pending as $request) {
                try {
                    $outcome = $sender->send($request, $client);
                } catch (\Throwable $e) {
                    $app->log->error(sprintf(
                        '[GovBrSatisfaction] falha ao processar a solicitação %d: %s',
                        $request->id,
                        $e->getMessage()
                    ));

                    continue;
                }

                // Só o transporte para a varredura; um 500 de uma linha é
                // problema dela, e as outras seguem.
                if ($outcome === SendOutcome::RetryTransport) {
                    $retry = true;

                    break;
                }

                if ($outcome === SendOutcome::RetryRow) {
                    $retryRows = true;
                }
            }

            // Uma exceção acima pode ter fechado a unidade de trabalho; o flush
            // estouraria e o job ficaria preso com erro.
            if ($app->em->isOpen()) {
                $app->em->flush();
            } else {
                $app->log->error('[GovBrSatisfaction] unidade de trabalho fechada; o lote não foi gravado');
            }
        } finally {
            $app->enableAccessControl();
        }

        // Sempre com replace: a varredura em execução ainda está na tabela e
        // sem replace o core a devolveria em vez de enfileirar outra.
        if ($retry) {
            $failures++;
            $when = self::BACKOFF[min($failures, count(self::BACKOFF) - 1)];

            $app->log->warning(sprintf(
                '[GovBrSatisfaction] transporte indisponível; próxima varredura %s',
                $when
            ));

            $app->enqueueOrReplaceJob(self::SLUG, ['failures' => $failures], $when);
        } elseif (count($pending) === self::BATCH_SIZE) {
            // Lote cheio escoa já, mesmo com linha em 500 no meio: o adiamento
            // dela não pode frear as outras.
            $app->enqueueOrReplaceJob(self::SLUG, ['failures' => 0]);
        } elseif ($retryRows) {
            // Transporte de pé: a contagem zera. Um minuto basta para o caso
            // conhecido (auditoria do BSC falhando após gravar) convergir.
            $app->enqueueOrReplaceJob(self::SLUG, ['failures' => 0], self::BACKOFF[1]);
        }

        return true;
    }
}
