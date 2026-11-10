<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use MapasCulturais\App;
use Tests\Traits\SpaceDirector;

/** O corpo enviado, contra o model do swagger. */
class PayloadTest extends TestCase
{
    use SpaceDirector;

    protected function montar(): array
    {
        $this->publicarEspaco();

        $id = (int) $this->solicitacoes()[0]['id'];
        $request = App::i()->repo(SatisfactionRequest::class)->find($id);

        return Payload::build($request, self::CPF);
    }

    /** Igualdade do conjunto de campos. */
    function testLevaExatamenteOsCamposDoContrato()
    {
        $payload = $this->montar();

        $contrato = [
            'cacheEvict', 'canalAvaliacao', 'canalPrestacao', 'cpfCidadao', 'cpfConsulta',
            'dataEtapa', 'dataSituacaoEtapa', 'email', 'etapa', 'ipOrigem', 'ipUsuario',
            'nomeCidadao', 'orgao', 'servico', 'sistemaSolicitante', 'situacaoEtapa', 'usuario',
        ];

        $this->assertSame($contrato, array_keys($payload));
    }

    /** Códigos e ids como string. */
    function testCodigosEIdsSaoStringComoNoContrato()
    {
        $payload = $this->montar();

        foreach (['canalAvaliacao', 'canalPrestacao', 'cpfCidadao', 'cpfConsulta', 'orgao', 'servico',
                  'situacaoEtapa', 'usuario'] as $campo) {
            $this->assertIsString($payload[$campo], "{$campo} é string no contrato");
            $this->assertMatchesRegularExpression('/^\d+$/', $payload[$campo], "{$campo} deve ser só dígitos");
        }

        $this->assertFalse($payload['cacheEvict']);
    }

    /** UTF-8 inválido vira U+FFFD. */
    function testEncodeSubstituiUtf8Invalido()
    {
        $json = Payload::encode(['nomeCidadao' => "Jo\xE3o"]);

        $this->assertIsArray(json_decode($json, true));
        $this->assertStringContainsString("Jo\u{FFFD}o", $json);
    }

    /** O protocolo é gerado pelo BSC. */
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

    /** O cadastro local grava com máscara; o login gov.br, sem. */
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

    function testUsuarioEhOCpf()
    {
        $payload = $this->montar();

        $this->assertSame($payload['cpfCidadao'], $payload['usuario']);
    }

    function testCpfConsultaAcompanhaODoCidadao()
    {
        $payload = $this->montar();

        $this->assertSame($payload['cpfCidadao'], $payload['cpfConsulta']);
    }

    /** A cópia gravada é mascarada e tem a codificação do fio. */
    function testGuardaOConteudoEnviadoMascarado()
    {
        $this->publicarEspaco();
        $this->processarEnvios();

        $gravado = $this->ultimaTentativa()['payload'];
        $guardado = json_decode($gravado, true);

        $this->assertIsArray($guardado, 'o conteúdo enviado não foi guardado');
        $this->assertSame('776.***.***-68', $guardado['cpfCidadao']);
        $this->assertSame($guardado['cpfCidadao'], $guardado['usuario']);
        $this->assertStringNotContainsString(self::CPF, $gravado, 'CPF por extenso na tabela');
        $this->assertSame($this->servico('espaco'), $guardado['servico']);

        $this->assertSame(Payload::encode($guardado), $gravado);
        $this->assertStringContainsString('"dataEtapa":"' . $guardado['dataEtapa'] . '"', $gravado);
        $this->assertStringNotContainsString('\/', $gravado);
    }

    /** A cópia não acompanha o cadastro. */
    function testOConteudoGuardadoNaoMudaComOCadastro()
    {
        $this->publicarEspaco();
        $this->processarEnvios();

        $antes = $this->ultimaTentativa()['payload'];

        $app = App::i();
        $agente = $this->perfilAtual();

        $app->disableAccessControl();
        $agente->name = 'Nome Trocado Depois do Envio';
        $agente->save(true);
        $app->em->flush();
        $app->enableAccessControl();

        $this->assertSame($antes, $this->ultimaTentativa()['payload']);
    }
}
