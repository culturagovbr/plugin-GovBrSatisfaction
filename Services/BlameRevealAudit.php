<?php

namespace GovBrSatisfaction\Services;

use MapasCulturais\App;

/**
 * Registro da revelação no MapasBlame.
 *
 * @package GovBrSatisfaction
 */
class BlameRevealAudit implements RevealAudit
{
    /** Prefixo das ações no blame_log. */
    const PREFIX = 'govbr-satisfaction';

    public function record(string $action, array $data): void
    {
        if (!isset(App::i()->plugins['MapasBlame'])) {
            return;
        }

        (new \MapasBlame\Request())->log(self::PREFIX . " {$action}", $data);
    }
}
