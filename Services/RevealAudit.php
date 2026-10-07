<?php

namespace GovBrSatisfaction\Services;

/**
 * Destino do registro de cada pedido de revelação.
 *
 * @package GovBrSatisfaction
 */
interface RevealAudit
{
    /** Registra a ação com os metadados, sem dado pessoal. */
    public function record(string $action, array $data): void;
}
