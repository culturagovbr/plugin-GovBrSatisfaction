<?php

namespace GovBrSatisfaction;

/**
 * Os seis serviços do Mapa da Cultura no Portal de Serviços do gov.br. O valor
 * é a chave em `config['servicos']` e em `texts.php`.
 *
 * @package GovBrSatisfaction
 */
enum Service: string
{
    case Registration = 'cadastro';
    case Collective = 'coletivo';
    case Opportunity = 'oportunidade';
    case Event = 'evento';
    case Space = 'espaco';
    case Project = 'projeto';

    /** Variável de ambiente com o id do serviço no Portal. */
    public function envVar(): string
    {
        return 'AVALIACAO_SERVICO_' . strtoupper($this->value);
    }

    /** Entidade cuja publicação conclui o serviço; nula para "Cadastrar-se". */
    public function entityType(): ?string
    {
        return match ($this) {
            self::Collective => 'Agent',
            self::Event => 'Event',
            self::Space => 'Space',
            self::Project => 'Project',
            self::Opportunity => 'Opportunity',
            self::Registration => null,
        };
    }

    public static function fromEntityType(string $entityType): ?self
    {
        foreach (self::cases() as $service) {
            if ($service->entityType() === $entityType) {
                return $service;
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
