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

    /** 500 da aplicação: o job se reagenda em pouco tempo. */
    case RetryRow;

    /** Token, rede, gateway ou proxy falharam: o job se reagenda com espaçamento crescente. */
    case RetryTransport;
}
