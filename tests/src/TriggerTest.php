<?php

namespace Tests\GovBrSatisfaction;

use MapasCulturais\App;
use MapasCulturais\Entity;
use Tests\Traits\EventDirector;
use Tests\Traits\ProjectDirector;
use Tests\Traits\SpaceDirector;

/**
 * O que dispara uma solicitação
 *
 * O gatilho é a publicação, e não a criação: as entidades do Mapas nascem como
 * rascunho, e pesquisar satisfação de um rascunho abandonado seria perguntar
 * sobre um serviço que a pessoa não concluiu.
 */
class TriggerTest extends TestCase
{
    use EventDirector;
    use ProjectDirector;
    use SpaceDirector;

    function testPublicarEspacoRegistra()
    {
        $this->publicar($this->spaceDirector->createSpace($this->cidadao->profile));

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

    /**
     * "Cadastrar-se" se conclui quando a pessoa confirma o e-mail, e não quando
     * a conta é criada: a pesquisa é entregue naquele endereço, e confirmar é a
     * prova de que ele existe e é dela.
     */
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

    /**
     * A situação é definida antes do insert, e não depois: as entidades nascem
     * com STATUS_ENABLED, então salvar e só então rebaixar para rascunho seria
     * publicar e despublicar — o que de fato é serviço concluído.
     */
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

    /**
     * O agente individual é o cadastro da própria pessoa, já coberto por
     * "Cadastrar-se". Contá-lo como coletivo faria a mesma pessoa aparecer duas
     * vezes por um serviço que prestou uma.
     */
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

    /**
     * A entidade que originou o disparo fica gravada para auditoria, com o tipo
     * normalizado pelo core — Opportunity, e não AgentOpportunity.
     */
    function testGuardaAOrigemDoDisparo()
    {
        $espaco = $this->spaceDirector->createSpace($this->cidadao->profile);
        $this->publicar($espaco);

        $linha = $this->solicitacoes()[0];

        $this->assertSame('Space', $linha['object_type']);
        $this->assertSame($espaco->id, (int) $linha['object_id']);
    }

    /**
     * "Criar e publicar" numa etapa também é serviço concluído.
     *
     * Agent, Event, Space e Project nascem com STATUS_ENABLED. Quem preenche o
     * formulário e publica de uma vez nunca passa por rascunho, então o gancho
     * de mudança de situação ou não dispara, ou dispara com a situação já
     * publicada — e a guarda que impede republicar descarta o caso.
     */
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
