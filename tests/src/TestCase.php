<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\Subsite;
use MapasCulturais\Entities\User;
use Tests\Traits\AgentDirector;
use Tests\Traits\UserDirector;

/**
 * Base dos testes do plugin
 *
 * Acrescenta à base do core o que todo teste daqui precisa: a tabela limpa e
 * um subsite de verdade apontado pela configuração. Sem ele o gatilho não
 * atenderia nada, e as regras de portal não seriam exercitáveis.
 */
abstract class TestCase extends \Tests\Abstract\TestCase
{
    use AgentDirector;
    use UserDirector;

    /** CPF de exemplo da documentação da API. */
    const CPF = '77689062768';

    protected Subsite $subsite;
    protected Subsite $outroSubsite;

    /** Usuário com CPF, dono das entidades publicadas nos testes. */
    protected User $cidadao;

    /** Configuração do plugin como as variáveis de ambiente a definiram. */
    private static ?array $configOriginal = null;

    protected function setUp(): void
    {
        parent::setUp();

        // O plugin é singleton da aplicação e sua configuração sobrevive de um
        // teste para o outro. Sem restaurar, um teste que esvazia uma variável
        // deixa os seguintes rodando com ela vazia.
        $this->restaurarConfiguracao();

        // Desliga o gatilho antes de montar o cenário. A configuração do plugin
        // sobrevive entre testes — ele é singleton da aplicação —, mas os
        // subsites não: o rollback do teste anterior os desfaz. Sem isto, o
        // usuário criado para ser dono do subsite dispararia uma solicitação
        // apontando para um portal que não existe mais.
        $this->configurar(['subsiteId' => 0]);

        // URL única por teste: a base do core não desfaz tudo entre um teste e
        // outro, e repetir o endereço faria o segundo subsite não ser gravado.
        $this->subsite = $this->criarSubsite('Portal atendido');
        $this->outroSubsite = $this->criarSubsite('Outro portal');

        $this->configurar(['subsiteId' => $this->subsite->id]);
        $this->noSubsite($this->subsite);

        $this->cidadao = $this->criarCidadao();

        // Criar e publicar entidade é ato de quem está logado: sem sessão, o
        // core entra no caminho de pedido de troca de titularidade e falha antes
        // de o gatilho ser alcançado.
        $this->login($this->cidadao);

        // Por último: criar o cidadão dispara o serviço "Cadastrar-se", e cada
        // teste precisa começar contando do zero.
        $this->conn()->executeStatement('DELETE FROM govbr_satisfaction_request');
    }

    protected function conn()
    {
        return App::i()->em->getConnection();
    }

    protected function restaurarConfiguracao(): void
    {
        $prop = new \ReflectionProperty(\MapasCulturais\Module::class, '_config');
        $prop->setAccessible(true);

        if (self::$configOriginal === null) {
            self::$configOriginal = $prop->getValue($this->plugin());
        }

        $prop->setValue($this->plugin(), self::$configOriginal);
    }

    protected function plugin(): Plugin
    {
        return App::i()->plugins['GovBrSatisfaction'];
    }

    /**
     * Sobrescreve a configuração do plugin em memória.
     *
     * A configuração nasce das variáveis de ambiente, que a suíte não pode
     * mudar entre um teste e outro. Instanciar um plugin novo não serve: o
     * construtor registra outro conjunto de ganchos, e os dois passariam a
     * responder ao mesmo gatilho.
     */
    protected function configurar(array $valores): void
    {
        $plugin = $this->plugin();

        $prop = new \ReflectionProperty(\MapasCulturais\Module::class, '_config');
        $prop->setAccessible(true);

        $prop->setValue($plugin, array_replace_recursive($prop->getValue($plugin), $valores));
    }

    /**
     * Passa a criar entidades dentro deste portal.
     */
    protected function noSubsite(Subsite $subsite): void
    {
        App::i()->setCurrentSubsiteId($subsite->id);
    }

    protected function criarSubsite(string $nome): Subsite
    {
        $app = App::i();
        $app->disableAccessControl();

        $sufixo = uniqid();

        $subsite = new Subsite;
        $subsite->name = "{$nome} {$sufixo}";
        $subsite->url = "{$sufixo}.teste";
        // Precisa ser um namespace com tema de verdade: ao processar jobs o core
        // carrega o tema do subsite, e um nome inventado quebraria com
        // "Class ... not found".
        $subsite->namespace = 'Subsite';
        $subsite->owner = $this->agentDirector->createAgent($this->userDirector->createUser());
        $subsite->save(true);
        $app->em->flush();

        $app->enableAccessControl();

        // Sem o subsite na tabela, o gatilho gravaria uma solicitação apontando
        // para um portal inexistente e a falha apareceria como violação de
        // chave estrangeira, longe da causa.
        $this->assertSame(
            1,
            (int) $this->conn()->fetchOne('SELECT count(*) FROM subsite WHERE id = ?', [$subsite->id]),
            'o subsite de teste não foi gravado'
        );

        return $subsite;
    }

    /**
     * Usuário com CPF no cadastro, que é o caso em que a solicitação é enviada.
     *
     * Não confirma o e-mail: quem quiser o serviço "Cadastrar-se" registrado
     * chama confirmarEmail() depois, como faz o fluxo de verdade.
     */
    protected function criarCidadao(): User
    {
        $app = App::i();
        $user = $this->userDirector->createUser();

        $app->disableAccessControl();
        $campo = $this->plugin()->config['metadataFieldCPF'];
        $user->profile->$campo = self::CPF;
        $user->profile->save(true);
        $app->em->flush();
        $app->enableAccessControl();

        return $user;
    }

    /**
     * Confirma o e-mail da conta, que é o gatilho de "Cadastrar-se".
     *
     * Reproduz o que o MultipleLocalAuth faz quando a pessoa clica no link
     * recebido: grava o metadado que marca a conta como ativa.
     */
    protected function confirmarEmail(User $user): void
    {
        $app = App::i();
        $app->disableAccessControl();

        // A linha de metadado é montada à mão em vez de por setMetadata(): o
        // registro daquela chave pertence ao MultipleLocalAuth, que não está
        // disponível na stack de testes do core. O que importa aqui é o gancho
        // de gravação, que é o mesmo nos dois caminhos.
        $chave = $this->plugin()->config['accountActiveMetadata'];

        $meta = new \MapasCulturais\Entities\UserMeta;
        $meta->owner = $user;
        $meta->key = $chave;
        $meta->value = '1';
        $meta->save(true);

        $app->em->flush();
        $app->enableAccessControl();
    }

    /**
     * Agente do tipo pedido.
     *
     * O director do core ignora o tipo passado — todo agente sai como coletivo —,
     * então o valor é atribuído aqui, que é o que distingue "Cadastrar coletivo"
     * do cadastro da própria pessoa.
     */
    protected function criarAgente(int $tipo): \MapasCulturais\Entities\Agent
    {
        $app = App::i();

        // O tipo vai na criação, não depois de gravar: é o que o formulário faz,
        // e é o que permite ao gatilho distinguir individual de coletivo já no
        // insert. Atribuir depois gravaria um agente sem tipo definido, que não
        // corresponde a nenhum caminho da interface.
        $app->disableAccessControl();
        $agente = $this->agentDirector->createAgent($this->cidadao, $tipo);
        $app->em->flush();
        $app->enableAccessControl();

        return $agente;
    }

    protected function solicitacoes(string $where = '1=1'): array
    {
        return $this->conn()->fetchAllAssociative(
            "SELECT * FROM govbr_satisfaction_request WHERE {$where} ORDER BY id"
        );
    }

    protected function contar(string $where = '1=1'): int
    {
        return (int) $this->conn()->fetchOne(
            "SELECT count(*) FROM govbr_satisfaction_request WHERE {$where}"
        );
    }

    /**
     * Cria já publicada, sem passar por rascunho.
     *
     * É o caminho "criar e publicar" da interface, e não um atalho de teste: as
     * entidades do core nascem com STATUS_ENABLED por padrão, então este é o
     * estado de quem preenche o formulário e publica de uma vez.
     */
    protected function criarPublicado($entidade): void
    {
        $app = App::i();

        $this->login($entidade->ownerUser);

        $app->disableAccessControl();

        $entidade->status = \MapasCulturais\Entity::STATUS_ENABLED;
        $entidade->save(true);

        $app->em->flush();
        $app->enableAccessControl();
    }

    /**
     * Publica uma entidade já criada, que é o gatilho de cinco dos seis serviços.
     */
    protected function publicar($entidade): void
    {
        $app = App::i();

        // Publicar é ato do dono. Sem ninguém logado, o core entra no caminho de
        // pedido de troca de titularidade e falha antes de chegar ao gatilho.
        $this->login($entidade->ownerUser);

        $app->disableAccessControl();

        $entidade->status = \MapasCulturais\Entity::STATUS_DRAFT;
        $entidade->save(true);

        $entidade->status = \MapasCulturais\Entity::STATUS_ENABLED;
        $entidade->save(true);

        $app->em->flush();
        $app->enableAccessControl();
    }

    /**
     * Processa a fila de envio.
     *
     * O job é enfileirado dentro de um subsite, e ao executá-lo o core
     * reinicializa o tema desse portal — que não constrói no contexto da suíte.
     * O envio não depende desse contexto: cada solicitação guarda o portal a que
     * pertence e o job revalida por ali. Então o vínculo é desfeito antes de
     * processar, e o que se exercita continua sendo o mesmo caminho.
     */
    protected function processarEnvios(): void
    {
        $app = App::i();

        // o job pode estar só na memória do EntityManager neste ponto
        $app->em->flush();

        $this->conn()->executeStatement('UPDATE job SET subsite_id = NULL');

        // sem isto o EntityManager devolveria o job que já tem em memória, com
        // o vínculo antigo, e o UPDATE acima não teria efeito nenhum
        $app->em->clear();

        $this->processJobs();
    }

    protected function assertSituacao(string $esperada, array $linha, string $mensagem = ''): void
    {
        $this->assertSame($esperada, $linha['send_status'], $mensagem);
    }

    protected function servico(string $chave): string
    {
        return (string) $this->plugin()->config['servicos'][$chave];
    }
}
