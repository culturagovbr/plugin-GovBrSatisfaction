<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Transporte da solicitação até o BSC.
 *
 * Duas implementações, real e de desenvolvimento, diferindo só no transporte:
 * o resto do fluxo é o mesmo código nos dois modos.
 *
 * O retorno é o desfecho do envio, em três possibilidades: o convite saiu, a
 * falha é transitória e vale repetir, ou o BSC recusou em definitivo. Tratar
 * recusa como envio faria a tabela afirmar que alguém foi convidado quando não
 * foi — e a coluna de situação é a única fonte sobre isso.
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
