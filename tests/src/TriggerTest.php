<?php

namespace Tests\GovBrSatisfaction;

use MapasCulturais\App;
use MapasCulturais\Entity;
use Tests\Builders\PhasePeriods\Open;
use Tests\Traits\EventDirector;
use Tests\Traits\OpportunityBuilder;
use Tests\Traits\ProjectDirector;
use Tests\Traits\SpaceDirector;

/** O que dispara uma solicitação. */
class TriggerTest extends TestCase
{
    use EventDirector;
    use OpportunityBuilder;
    use ProjectDirector;
    use SpaceDirector;

    /** Opportunity: tipo normalizado. */
    function testPublicarOportunidadeRegistra()
    {
        $oportunidade = $this->opportunityBuilder
            ->reset(owner: $this->cidadao->profile, owner_entity: $this->cidadao->profile)
            ->fillRequiredProperties()
            ->firstPhase()
                ->setRegistrationPeriod(new Open)
                ->done()
            ->save()
            ->getInstance();

        $this->publicar($oportunidade);

        $this->assertSame(1, $this->contar("servico = '{$this->servico('oportunidade')}'"));
        $this->assertSame('Opportunity', $this->solicitacoes()[0]['object_type']);
    }

    function testPublicarEspacoRegistra()
    {
        $this->publicarEspaco();

        $this->assertSame(1, $this->contar("servico = '{$this->servico('espaco')}'"));
    }

    function testPublicarProjetoRegistra()
    {
        $this->publicar($this->projectDirector->createProject($this->cidadao->profile));

        $this->assertSame(1, $this->contar("servico = '{$this->servico('projeto')}'"));
    }

    function testPublicarEventoRegistra()
    {
        $this->publicar($this->eventDirector->createEvent($this->cidadao->profile));

        $this->assertSame(1, $this->contar("servico = '{$this->servico('evento')}'"));
    }

    /** Cadastrar-se: confirmação do e-mail. */
    function testConfirmarEmailRegistra()
    {
        $this->confirmarEmail($this->criarCidadao());

        $this->assertSame(1, $this->contar("servico = '{$this->servico('cadastro')}'"));
    }

    function testCriarContaSemConfirmarNaoRegistra()
    {
        $this->criarCidadao();

        $this->assertSame(0, $this->contar("servico = '{$this->servico('cadastro')}'"));
    }

    /** Rascunho não registra. */
    function testRascunhoNaoRegistra()
    {
        $espaco = $this->spaceDirector->createSpace($this->cidadao->profile, save: false);

        App::i()->disableAccessControl();
        $espaco->status = Entity::STATUS_DRAFT;
        $espaco->save(true);
        App::i()->em->flush();
        App::i()->enableAccessControl();

        $this->assertSame(0, $this->contar("servico = '{$this->servico('espaco')}'"));
    }

    /** Agente individual não conta como coletivo. */
    function testAgenteIndividualNaoContaComoColetivo()
    {
        $this->publicar($this->criarAgente(1));

        $this->assertSame(0, $this->contar("servico = '{$this->servico('coletivo')}'"));
    }

    function testAgenteColetivoRegistra()
    {
        $this->publicar($this->criarAgente(2));

        $this->assertSame(1, $this->contar("servico = '{$this->servico('coletivo')}'"));
    }

    function testGuardaAOrigemDoDisparo()
    {
        $espaco = $this->spaceDirector->createSpace($this->cidadao->profile);
        $this->publicar($espaco);

        $linha = $this->solicitacoes()[0];

        $this->assertSame('Space', $linha['object_type']);
        $this->assertSame($espaco->id, (int) $linha['object_id']);
    }

    /**
     * Só o primeiro IP do cabeçalho, até 45 chars.
     *
     * @dataProvider cabecalhosDeIp
     */
    function testGuardaSoOPrimeiroIpDoCabecalho(?string $cabecalho, ?string $esperado)
    {
        $this->assertSame($esperado, \GovBrSatisfaction\Services\SatisfactionRegistry::firstIp($cabecalho));
    }

    public static function cabecalhosDeIp(): array
    {
        return [
            'um ip' => ['10.0.0.1', '10.0.0.1'],
            'cadeia de proxies' => ['10.0.0.1, 172.16.0.1, 192.168.0.1', '10.0.0.1'],
            'ipv6 com espaços' => [' 2001:db8::1 , 10.0.0.1', '2001:db8::1'],
            'vazio' => ['', null],
            'nulo' => [null, null],
            'longo demais' => [str_repeat('9', 60), str_repeat('9', 45)],
        ];
    }

    /**
     * Entidade que nasce publicada registra.
     *
     * @dataProvider entidadesQueNascemPublicadas
     */
    function testEntidadeQueNascePublicadaRegistra(string $servico, string $criar)
    {
        $this->$criar();

        $this->assertSame(1, $this->contar("servico = '{$this->servico($servico)}'"), "{$servico} não registrou ao nascer publicado");
    }

    public static function entidadesQueNascemPublicadas(): array
    {
        return [
            'evento' => ['evento', 'criarEvento'],
            'projeto' => ['projeto', 'criarProjeto'],
            'coletivo' => ['coletivo', 'criarColetivo'],
        ];
    }

    protected function criarEvento(): void
    {
        $this->eventDirector->createEvent($this->cidadao->profile);
    }

    protected function criarProjeto(): void
    {
        $this->projectDirector->createProject($this->cidadao->profile);
    }

    protected function criarColetivo(): void
    {
        $this->criarAgente(2);
    }

    /** Criar e publicar numa etapa registra. */
    function testCriarJaPublicadoRegistra()
    {
        $this->criarPublicado($this->spaceDirector->createSpace($this->cidadao->profile));

        $this->assertSame(
            1,
            $this->contar("servico = '{$this->servico('espaco')}'"),
            'criar e publicar numa etapa não registrou'
        );
    }
}
