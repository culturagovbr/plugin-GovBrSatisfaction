<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Desfecho de um envio e a resposta da API.
 *
 * @package GovBrSatisfaction
 */
final class Result
{
    /** Tamanho da coluna `send_detail`. */
    const DETAIL_MAX = 500;

    /**
     * @param int|null    $status Código HTTP, ou nulo quando a requisição não chegou a sair
     * @param string|null $detail Resumo legível
     * @param string|null $body   O corpo da resposta, sem a pilha de exceção
     */
    public function __construct(
        public readonly Outcome $outcome,
        public readonly ?int $status = null,
        public readonly ?string $detail = null,
        public readonly ?string $body = null,
        public readonly ?Exchange $exchange = null,
    ) {
    }

    /** Cópia com a requisição que a produziu. */
    public function withExchange(Exchange $exchange): self
    {
        return new self($this->outcome, $this->status, $this->detail, $this->body, $exchange);
    }
}
