<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Requisição de um envio ao BSC, para o histórico.
 *
 * @package GovBrSatisfaction
 */
final class Exchange
{
    /**
     * @param string[]|null $responseHeaders Linhas de cabeçalho da resposta
     */
    public function __construct(
        public readonly string $method,
        public readonly string $endpoint,
        public readonly \DateTime $sentAt,
        public readonly ?int $durationMs = null,
        public readonly ?array $responseHeaders = null,
        public readonly bool $simulated = false,
    ) {
    }
}
