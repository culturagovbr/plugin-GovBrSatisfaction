<?php

namespace GovBrSatisfaction;

/**
 * Os seis serviços do Mapa da Cultura no Portal de Serviços do gov.br. O valor
 * é a chave em `config['servicos']` e em `texts.php`.
 *
 * @package GovBrSatisfaction
 */
enum Servico: string
{
    case Cadastro = 'cadastro';
    case Coletivo = 'coletivo';
    case Oportunidade = 'oportunidade';
    case Evento = 'evento';
    case Espaco = 'espaco';
    case Projeto = 'projeto';

    /** Variável de ambiente com o id do serviço no Portal. */
    public function envVar(): string
    {
        return 'AVALIACAO_SERVICO_' . strtoupper($this->value);
    }

    /** Entidade cuja publicação conclui o serviço; nula para "Cadastrar-se". */
    public function entityType(): ?string
    {
        return match ($this) {
            self::Coletivo => 'Agent',
            self::Evento => 'Event',
            self::Espaco => 'Space',
            self::Projeto => 'Project',
            self::Oportunidade => 'Opportunity',
            self::Cadastro => null,
        };
    }

    public static function fromEntityType(string $entityType): ?self
    {
        foreach (self::cases() as $servico) {
            if ($servico->entityType() === $entityType) {
                return $servico;
            }
        }

        return null;
    }

    /** @return string[] */
    public static function entityTypes(): array
    {
        return array_values(array_filter(array_map(fn(self $s) => $s->entityType(), self::cases())));
    }
}
