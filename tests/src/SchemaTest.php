<?php

namespace Tests\GovBrSatisfaction;

/** As tabelas e o mapeamento das entidades. */
class SchemaTest extends TestCase
{
    const TABELA = 'govbr_satisfaction_request';

    protected function colunas(string $tabela = self::TABELA): array
    {
        return $this->conn()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY column_name',
            [$tabela]
        );
    }

    function testTabelaExiste()
    {
        $this->assertTrue((bool) $this->conn()->fetchOne(
            'SELECT 1 FROM information_schema.tables WHERE table_name = ?',
            [self::TABELA]
        ));
    }

    function testGuardaOQueOEnvioPrecisa()
    {
        $colunas = $this->colunas();

        foreach (['user_id', 'servico', 'subsite_id', 'send_status', 'etapa', 'data_etapa',
                  'situacao_etapa', 'canal_prestacao', 'canal_avaliacao', 'orgao',
                  'ip_origem', 'ip_usuario', 'create_timestamp', 'send_timestamp'] as $coluna) {
            $this->assertContains($coluna, $colunas);
        }
    }

    /** Sem coluna de dado pessoal. */
    function testNaoTemColunaDeDadoPessoal()
    {
        $colunas = $this->colunas();

        foreach (['cpf', 'documento', 'nome', 'email'] as $proibida) {
            $this->assertNotContains($proibida, $colunas);
        }
    }

    function testIndiceUnicoPorUsuarioEServico()
    {
        // a chave primária também é única; não é a que interessa
        $definicao = $this->conn()->fetchOne(
            "SELECT indexdef FROM pg_indexes
              WHERE tablename = ? AND indexdef ILIKE '%UNIQUE%' AND indexname NOT LIKE '%\_pkey'",
            [self::TABELA]
        );

        $this->assertNotFalse($definicao, 'a tabela não tem índice único');
        $this->assertStringContainsString('user_id', $definicao);
        $this->assertStringContainsString('servico', $definicao);
    }

    /** @dataProvider entidades */
    function testEntidadeMapeiaAsColunasDaTabela(string $classe, string $tabela)
    {
        $md = \MapasCulturais\App::i()->em->getClassMetadata($classe);

        $mapeadas = array_map(fn($campo) => $md->getColumnName($campo), $md->getFieldNames());

        foreach ($md->getAssociationMappings() as $associacao) {
            foreach ($associacao['joinColumns'] ?? [] as $juncao) {
                $mapeadas[] = $juncao['name'];
            }
        }

        foreach ($mapeadas as $coluna) {
            $this->assertContains($coluna, $this->colunas($tabela), "coluna {$coluna} mapeada mas ausente");
        }
    }

    public static function entidades(): array
    {
        return [
            'solicitação' => [\GovBrSatisfaction\Entities\SatisfactionRequest::class, self::TABELA],
            'envio' => [\GovBrSatisfaction\Entities\SatisfactionDispatch::class, 'govbr_satisfaction_dispatch'],
            'tentativa' => [\GovBrSatisfaction\Entities\SatisfactionAttempt::class, 'govbr_satisfaction_attempt'],
        ];
    }

    /**
     * Histórico sem coluna de dado pessoal.
     *
     * @dataProvider tabelasDoHistorico
     */
    function testHistoricoNaoTemColunaDeDadoPessoal(string $tabela)
    {
        $colunas = $this->colunas($tabela);

        $this->assertNotEmpty($colunas, "tabela {$tabela} ausente");

        foreach (['cpf', 'documento', 'nome', 'email'] as $proibida) {
            $this->assertNotContains($proibida, $colunas);
        }
    }

    public static function tabelasDoHistorico(): array
    {
        return [
            'envio' => ['govbr_satisfaction_dispatch'],
            'tentativa' => ['govbr_satisfaction_attempt'],
        ];
    }

    function testUuidDoEnvioEhUnico()
    {
        $definicao = $this->conn()->fetchOne(
            "SELECT indexdef FROM pg_indexes
              WHERE tablename = 'govbr_satisfaction_dispatch' AND indexdef ILIKE '%UNIQUE%' AND indexname NOT LIKE '%\_pkey'"
        );

        $this->assertNotFalse($definicao, 'o envio não tem índice único');
        $this->assertStringContainsString('uuid', $definicao);
    }
}
