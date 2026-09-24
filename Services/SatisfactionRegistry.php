<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Jobs\SendSatisfactionRequestJob;
use GovBrSatisfaction\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\User;

/**
 * Registro das solicitações de avaliação
 *
 * Entre os ganchos e o banco: decide se um serviço concluído vira linha na
 * tabela — portal atendido, configuração completa, ainda não avaliado — e grava.
 *
 * Grava de forma síncrona, na requisição de quem concluiu; só o envio ao BSC
 * vai para a fila. E nada aqui derruba essa requisição: a avaliação é acessório
 * — ver `registerRequest()`.
 *
 * @package GovBrSatisfaction
 */
class SatisfactionRegistry
{
    private Plugin $plugin;

    /**
     * Entidades marcadas pelo gancho de publicação, aguardando o fim do save.
     *
     * O gancho de situação roda antes de a entidade existir no banco, então não
     * há id para guardar; a marcação espera o `save:finish`. Como os dois são
     * ganchos distintos, o Plugin mantém uma instância desta classe viva.
     *
     * A chave é o objeto, não spl_object_id: o PHP reaproveita esse número após
     * a coleta, e uma marca órfã — save interrompido por validação — seria
     * herdada por outra entidade, registrando o serviço errado. O custo é
     * segurar a entidade em memória, o que é melhor que dado errado no gov.br.
     *
     * @var \SplObjectStorage<Entity,string> entidade => chave do serviço
     */
    private \SplObjectStorage $marked;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
        $this->marked = new \SplObjectStorage;
    }

    /**
     * Marca uma entidade recém-publicada para registro ao fim do save.
     */
    public function markForRegistration(Entity $entity): void
    {
        $servico = Plugin::PUBLISHED_ENTITIES[$entity->getEntityType()] ?? null;

        if (!$servico) {
            return;
        }

        if ($entity instanceof Agent && $this->isIndividualAgent($entity)) {
            return;
        }

        $this->marked[$entity] = $servico;
    }

    /**
     * Registra uma entidade que nasceu publicada.
     *
     * Passa pelos mesmos filtros da publicação em duas etapas — tipo atendido,
     * agente individual de fora, dono resolvido —, e por `registerMarked` para
     * que a marca seja consumida e o `save:finish` seguinte não repita.
     */
    public function registerOnCreate(Entity $entity): void
    {
        $this->markForRegistration($entity);
        $this->registerMarked($entity);
    }

    /**
     * Cria a solicitação da entidade marcada, agora que ela tem id.
     */
    public function registerMarked(Entity $entity): void
    {
        if (!isset($this->marked[$entity])) {
            return;
        }

        $servico = $this->marked[$entity];
        unset($this->marked[$entity]);

        $user = $entity->ownerUser;

        if ($user instanceof User) {
            $this->registerRequest($user, $servico, $entity);
        }
    }

    /**
     * Registra o serviço concluído, sem nunca derrubar quem o concluiu.
     *
     * Roda na requisição de uma pessoa de verdade, e perder a avaliação
     * incomoda menos que devolver erro a quem acabou de publicar; o log impede
     * que a perda seja silenciosa.
     *
     * A gravação usa as mesmas defesas de Security\Services\IpRegistry —
     * consulta antes, `isOpen()`, controle de acesso em try/finally. Este try é
     * a última rede.
     */
    public function registerRequest(User $user, string $servicoKey, ?Entity $entity): void
    {
        try {
            $this->registrar($user, $servicoKey, $entity);
        } catch (\Throwable $e) {
            App::i()->log->error(sprintf(
                '[GovBrSatisfaction] falha ao registrar o serviço %s do usuário %d: %s',
                $servicoKey,
                $user->id,
                $e->getMessage()
            ));
        }
    }

    private function registrar(User $user, string $servicoKey, ?Entity $entity): void
    {
        $app = App::i();
        $config = $this->plugin->config;

        // A entidade carrega o subsite a que pertence. O usuário não — contas
        // são globais —, então "Cadastrar-se" usa o portal em que a pessoa
        // estava quando se cadastrou.
        $subsite = $entity ? $entity->subsite : $app->getCurrentSubsite();

        $recusa = $this->plugin->subsiteRejectionReason($subsite);

        if ($recusa) {
            $app->log->debug("[GovBrSatisfaction] serviço concluído fora do portal atendido: {$recusa}");

            return;
        }

        // Configuração incompleta não gera registro: gravar agora e resolver
        // depois só acumularia fila esperando alguém arrumar o .env. O painel
        // avisa qual variável falta.
        $missing = $this->plugin->isDevMode() ? [] : $this->plugin->missingConfig();

        if ($missing) {
            $app->log->warning(sprintf(
                '[GovBrSatisfaction] serviço concluído não registrado: faltam %s',
                implode(', ', $missing)
            ));

            return;
        }

        $servico = $config['servicos'][$servicoKey] ?? '';

        // Em desenvolvimento a checagem de configuração é pulada, e um id vazio
        // faria os seis serviços colapsarem na mesma chave (user_id, servico):
        // o primeiro concluído bloquearia os outros cinco no índice único.
        if ($servico === '') {
            $app->log->warning("[GovBrSatisfaction] serviço {$servicoKey} sem id configurado; nada registrado");

            return;
        }

        // Uma solicitação por usuário e serviço. A consulta resolve o caso
        // comum — a pessoa concluindo o mesmo serviço de novo —, e o índice
        // único do banco continua sendo a garantia final.
        $existente = $app->repo(SatisfactionRequest::class)->findOneBy([
            'user' => $user,
            'servico' => $servico,
        ]);

        if ($existente) {
            return;
        }

        // Unidade de trabalho já fechada por outro motivo não aceita gravação, e
        // insistir só repetiria a exceção. Mesma defesa que Security\Services\
        // IpRegistry faz antes de gravar um bloqueio.
        if (!$app->em->isOpen()) {
            $app->log->error("[GovBrSatisfaction] unidade de trabalho fechada, serviço {$servicoKey} não registrado");

            return;
        }

        $request = new SatisfactionRequest;
        $request->user = $user;
        $request->servico = $servico;
        $request->subsite = $subsite;

        // getEntityType(), e não get_class(): as concretas de Opportunity fariam
        // a coluna Origem dizer "AgentOpportunity". É o nome usado nos ganchos.
        $request->objectType = $entity ? $entity->getEntityType() : null;
        $request->objectId = $entity ? $entity->id : null;

        $request->sendStatus = SatisfactionRequest::STATUS_PENDING;
        $request->etapa = $config['etapa'];
        $request->dataEtapa = new \DateTime();
        $request->situacaoEtapa = $config['situacaoEtapa'];
        $request->canalPrestacao = $config['canalPrestacao'];
        $request->canalAvaliacao = $config['canalAvaliacao'];
        $request->orgao = $config['orgao'] ?: null;

        // Capturados aqui, na requisição de quem concluiu o serviço. O job monta
        // o payload depois, em linha de comando, onde não há requisição para ler.
        $request->ipOrigem = $_SERVER['SERVER_ADDR'] ?? null;
        $request->ipUsuario = $this->ipUsuario();

        $app->disableAccessControl();

        try {
            $request->save(true);
        } finally {
            $app->enableAccessControl();
        }

        // Um único job de varredura por vez: o id gerado é constante, então cada
        // novo registro apenas garante que há uma varredura na fila, em vez de
        // empilhar uma execução por solicitação.
        //
        // Sem `replace` de propósito: quando já existe varredura enfileirada, o
        // core devolve a existente em vez de recriar. Isso preserva o adiamento
        // que o job marca quando o BSC está fora — com replace, cada publicação
        // durante uma queda puxaria a próxima tentativa para agora, e o
        // espaçamento não existiria na prática.
        $app->enqueueJob(SendSatisfactionRequestJob::SLUG, []);
    }

    /**
     * Endereço de quem concluiu o serviço, quando há requisição.
     *
     * O gatilho costuma rodar numa requisição web, mas publicação também
     * acontece por script e por importação, e ali `$app->request` é nulo. Sem
     * IP a solicitação segue mesmo assim: o campo é informativo para o gov.br,
     * e perdê-lo não é motivo para deixar de registrar o serviço prestado.
     */
    private function ipUsuario(): ?string
    {
        $request = App::i()->request;

        return $request ? ($request->getIp() ?: null) : null;
    }

    private function isIndividualAgent(Agent $agent): bool
    {
        return isset($agent->type->id) && $agent->type->id == $this->plugin->config['agentTypeIndividual'];
    }
}
