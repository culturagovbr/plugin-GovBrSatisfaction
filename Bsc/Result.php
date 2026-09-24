<?php

namespace GovBrSatisfaction\Bsc;

/**
 * Desfecho de um envio ao BSC
 *
 * Carrega o que aconteceu e o que a API respondeu. O retorno não é lido para
 * alimentar regra de negócio — essa decisão da equipe da API continua valendo —,
 * mas para que a recusa seja diagnosticável: durante a homologação, o código de
 * estado e a mensagem foram a única forma de distinguir indisponibilidade de
 * payload inválido, e viviam apenas no log, fora do alcance de quem opera.
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

    /** Uma das constantes acima. */
    public string $outcome;

    /** Código HTTP, ou nulo quando a requisição não chegou a sair. */
    public ?int $status;

    /** Resumo legível, para a tabela do painel. */
    public ?string $detail;

    /** O corpo da resposta, como veio — sem recorte nem interpretação. */
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
