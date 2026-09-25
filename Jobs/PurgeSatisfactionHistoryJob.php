<?php

namespace GovBrSatisfaction\Jobs;

use GovBrSatisfaction\Plugin;
use GovBrSatisfaction\Services\DispatchLog;
use MapasCulturais\App;
use MapasCulturais\Definitions\JobType;
use MapasCulturais\Entities\Job;

/**
 * Apaga, uma vez por dia, o payload cifrado e a resposta das tentativas antigas.
 *
 * @package GovBrSatisfaction
 */
class PurgeSatisfactionHistoryJob extends JobType
{
    const SLUG = 'govbr-satisfaction-purge';

    /** Repetições do job diário, na prática sem fim. */
    const ITERATIONS = 36500;

    /** Um só na fila. */
    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return self::SLUG;
    }

    /** Agenda o expurgo diário, se ainda não estiver na fila. */
    public static function schedule(): void
    {
        App::i()->enqueueJob(self::SLUG, [], 'tomorrow 03:00', '+1 day', self::ITERATIONS);
    }

    /** Expurga pelo prazo configurado; devolve quantas tentativas foram limpas. */
    public static function purgeNow(): int
    {
        $plugin = Plugin::instance();
        $days = $plugin ? (int) $plugin->config['retentionDays'] : 0;

        if ($days < 1) {
            return 0;
        }

        $purged = (new DispatchLog())->purge(new \DateTime("-{$days} days"));

        if ($purged) {
            App::i()->log->info(sprintf(
                '[GovBrSatisfaction] expurgo: payload cifrado e resposta apagados de %d tentativa(s) com mais de %d dias',
                $purged,
                $days
            ));
        }

        return $purged;
    }

    protected function _execute(Job $job)
    {
        self::purgeNow();

        return true;
    }
}
