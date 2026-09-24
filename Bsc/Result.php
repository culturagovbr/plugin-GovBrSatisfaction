<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Desfecho de um envio ao BSC: o que aconteceu e o que a API respondeu, para
 * que a recusa seja diagnosticável no painel e não só no log.
 *
 * @package GovBrSatisfaction
 */
class Result
{
    /** O BSC aceitou; o cidadão foi convidado. */
    const SENT = 'enviado';

    /** Falha transitória ou anterior ao despacho: a varredura tenta de novo. */
    const RETRY = 'retentar';

    /** Recusa definitiva: ninguém foi convidado, e repetir não muda isso. */
    const REJECTED = 'recusado';

    /** Tamanho da coluna `send_detail`. */
    const DETAIL_MAX = 500;

    /** Uma das constantes acima. */
    public string $outcome;

    /** Código HTTP, ou nulo quando a requisição não chegou a sair. */
    public ?int $status;

    /** Resumo legível, para a tabela do painel. */
    public ?string $detail;

    /** O corpo da resposta, sem a pilha de exceção. */
    public ?string $body;

    public function __construct(
        string $outcome,
        ?int $status = null,
        ?string $detail = null,
        ?string $body = null
    ) {
        $this->outcome = $outcome;
        $this->status = $status;
        $this->detail = $detail;
        $this->body = $body;
    }
}
