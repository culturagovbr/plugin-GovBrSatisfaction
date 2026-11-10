<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Transporte da solicitação até o BSC.
 *
 * @package GovBrSatisfaction
 */
interface Client
{
    /**
     * @param array $payload Corpo de POST /api/avaliacao/completa
     */
    public function send(array $payload): Result;
}
