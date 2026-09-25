<?php

namespace GovBrSatisfaction\Services;

/**
 * O que a varredura faz depois de o Sender resolver uma linha.
 *
 * @package GovBrSatisfaction
 */
enum SendOutcome
{
    /** A linha saiu da fila: enviada, recusada, sem CPF ou descartada. */
    case Done;

    /** 500 da aplicação para esta linha; a varredura segue para a próxima. */
    case RetryRow;

    /** Token, rede, gateway ou proxy falharam; a varredura para e se adia. */
    case RetryTransport;
}
