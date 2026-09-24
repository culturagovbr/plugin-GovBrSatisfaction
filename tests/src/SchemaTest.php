<?php

namespace Tests\GovBrSatisfaction;

/**
 * A tabela do plugin e o mapeamento da entidade
 *
 * O entrypoint da suíte executa os updates de banco a cada inicialização, então
 * a tabela nasce sozinha quando o plugin está ativo. Sem estes testes, um erro
 * no arquivo de updates só apareceria quando alguém publicasse algo.
 */
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
     * A tabela não guarda dado pessoal: CPF, nome e e-mail são lidos do cadastro
     * no momento do envio. Uma coluna dessas aparecendo aqui significa que
     * alguém passou a duplicar dado pessoal sem querer.
     */
    function testNaoGuardaDadoPessoal()
    {
        $colunas = $this->colunas();

        foreach (['cpf', 'documento', 'nome', 'email'] as $proibida) {
            $this->assertNotContains($proibida, $colunas);
        }
    }

    /**
     * A regra de uma avaliação por serviço é garantida pelo banco, e não pelo
     * código: sem o índice, duas publicações simultâneas criariam duas linhas.
     */
    function testIndiceUnicoPorUsuarioEServico()
    {
        // a chave primária também é um índice único, e não é a que interessa aqui
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
