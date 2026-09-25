<?php

namespace GovBrSatisfaction\Services;

/**
 * O que o job faz depois de o Sender resolver a solicitação.
 *
 * @package GovBrSatisfaction
 */
enum SendOutcome
{
    /** A linha saiu da fila: enviada, recusada, sem CPF ou descartada. */
    case Done;

    /** Falhou e ainda há tentativa: o job se reagenda. */
    case Retry;
}
