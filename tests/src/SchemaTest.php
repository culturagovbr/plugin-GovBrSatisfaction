<?php

namespace Tests\GovBrSatisfaction;

/** A tabela e o mapeamento da entidade. */
class SchemaTest extends TestCase
{
    const TABELA = 'govbr_satisfaction_request';

    protected function colunas(): array
    {
        return $this->conn()->fetchFirstColumn(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY column_name',
            [self::TABELA]
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

    /**
     * A cópia do envio em `send_payload` é assumida; coluna `cpf` ou `email`
     * seria duplicação nova.
     */
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

    function testEntidadeMapeiaAsColunasDaTabela()
    {
        $md = \MapasCulturais\App::i()->em->getClassMetadata(
            \GovBrSatisfaction\Entities\SatisfactionRequest::class
        );

        $mapeadas = array_map(fn($campo) => $md->getColumnName($campo), $md->getFieldNames());

        foreach ($mapeadas as $coluna) {
            $this->assertContains($coluna, $this->colunas(), "coluna {$coluna} mapeada mas ausente");
        }
    }
}
