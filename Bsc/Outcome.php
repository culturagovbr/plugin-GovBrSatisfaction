<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Desfecho de um envio ao BSC.
 *
 * @package GovBrSatisfaction
 */
enum Outcome: string
{
    /** O BSC aceitou; o cidadão foi convidado. */
    case Sent = 'enviado';

    /** Falha transitória: o job tenta de novo. */
    case Retry = 'retentar';

    /** Recusa definitiva: ninguém foi convidado, e repetir não muda isso. */
    case Rejected = 'recusado';
}
