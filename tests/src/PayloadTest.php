<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use MapasCulturais\App;
use Tests\Traits\SpaceDirector;

/**
 * O conteúdo enviado ao BSC
 *
 * O contrato é de outra equipe, e um campo com nome ou formato errado só
 * apareceria como recusa silenciosa — a API não devolve confirmação e o plugin
 * não lê a resposta.
 */
class PayloadTest extends TestCase
{
    use SpaceDirector;

    protected function montar(): array
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

        $id = (int) $this->solicitacoes()[0]['id'];
        $request = App::i()->repo(SatisfactionRequest::class)->find($id);

        return Payload::build($request, self::CPF);
    }

    function testLevaOsCamposObrigatoriosDaApi()
    {
        $payload = $this->montar();

        foreach (['cpfCidadao', 'cpfConsulta', 'nomeCidadao', 'email', 'usuario', 'orgao', 'servico',
                  'sistemaSolicitante', 'canalPrestacao', 'canalAvaliacao', 'etapa',
                  'situacaoEtapa', 'dataEtapa', 'dataSituacaoEtapa',
                  'ipOrigem', 'ipUsuario'] as $campo) {
            $this->assertArrayHasKey($campo, $payload);
        }
    }

    /**
     * O endpoint de avaliação completa gera o protocolo do lado do BSC. Mandar
     * um seria enviar campo que o contrato não declara.
     */
    function testNaoMandaProtocolo()
    {
        $this->assertArrayNotHasKey('protocolo', $this->montar());
    }

    function testDatasNoFormatoDoGovBr()
    {
        $payload = $this->montar();

        $this->assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4}$#', $payload['dataEtapa']);
        $this->assertSame($payload['dataEtapa'], $payload['dataSituacaoEtapa']);
    }

    function testCpfVaiSoComDigitos()
    {
        $this->assertSame(self::CPF, $this->montar()['cpfCidadao']);
    }

    function testIdentificaOSistemaEOServicoPrestado()
    {
        $payload = $this->montar();

        $this->assertSame('Mapa da Cultura', $payload['sistemaSolicitante']);
        $this->assertSame($this->servico('espaco'), $payload['servico']);

        // 8 é Web e 2 é Concluído, na tabela da documentação da API
        $this->assertSame('8', $payload['canalPrestacao']);
        $this->assertSame('2', $payload['situacaoEtapa']);
    }

    /**
     * O CPF é lido do cadastro, com máscara ou sem — o cadastro local grava
     * formatado e o login gov.br grava só os dígitos.
     */
    function testLeOCpfComOuSemMascara()
    {
        $campo = $this->plugin()->config['metadataFieldCPF'];

        App::i()->disableAccessControl();
        $this->cidadao->profile->$campo = '776.890.627-68';
        $this->cidadao->profile->save(true);
        App::i()->em->flush();
        App::i()->enableAccessControl();

        $this->assertSame(self::CPF, Payload::cpf($this->cidadao, $campo));
    }

    /**
     * `usuario` identifica quem fez a requisição, e o contrato aceita login,
     * cpf ou identificador. Vai o CPF, que é o que o gov.br reconhece.
     */
    function testUsuarioEhOCpf()
    {
        $payload = $this->montar();

        $this->assertSame($payload['cpfCidadao'], $payload['usuario']);
    }

    /**
     * Quem concluiu o serviço é a mesma pessoa que a avaliação consulta.
     */
    function testCpfConsultaAcompanhaODoCidadao()
    {
        $payload = $this->montar();

        $this->assertSame($payload['cpfCidadao'], $payload['cpfConsulta']);
    }

    /**
     * Auditoria precisa do que saiu, não do que sairia hoje.
     *
     * Sem a cópia, o painel reconstruiria o conteúdo a partir do cadastro atual
     * — e quem corrigiu o CPF ou trocou o e-mail depois do envio apareceria com
     * os valores de agora, afirmando que foram esses que foram enviados.
     */
    function testGuardaOConteudoEnviado()
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));
        $this->processarEnvios();

        $linha = $this->solicitacoes()[0];
        $guardado = json_decode($linha['send_payload'], true);

        $this->assertIsArray($guardado, 'o conteúdo enviado não foi guardado');
        $this->assertSame(self::CPF, $guardado['cpfCidadao']);
        $this->assertSame($this->servico('espaco'), $guardado['servico']);
    }

    /**
     * E a cópia não acompanha mudanças posteriores do cadastro.
     */
    function testOConteudoGuardadoNaoMudaComOCadastro()
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));
        $this->processarEnvios();

        $antes = $this->solicitacoes()[0]['send_payload'];

        $app = App::i();

        // processarEnvios() limpa o EntityManager, então o agente em memória
        // está destacado; alterá-lo direto faria o Doctrine tentar persistir de
        // novo as associações inteiras
        $agente = $app->repo('Agent')->find($this->cidadao->profile->id);

        $app->disableAccessControl();
        $agente->name = 'Nome Trocado Depois do Envio';
        $agente->save(true);
        $app->em->flush();
        $app->enableAccessControl();

        $this->assertSame($antes, $this->solicitacoes()[0]['send_payload']);
    }
}
