<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Jobs\SendSatisfactionRequestJob;
use GovBrSatisfaction\Plugin;
use GovBrSatisfaction\Servico;
use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\User;

/**
 * Registra a solicitação de um serviço concluído.
 *
 * @package GovBrSatisfaction
 */
class SatisfactionRegistry
{
    /**
     * Entidades marcadas no gancho de status, aguardando o save:finish (antes
     * dele não há id). Chave é o objeto, não spl_object_id: o PHP reaproveita
     * esse número após a coleta.
     *
     * @var \SplObjectStorage<Entity,Servico>
     */
    private \SplObjectStorage $marked;

    public function __construct(private readonly Plugin $plugin)
    {
        $this->marked = new \SplObjectStorage;
    }

    public function markForRegistration(Entity $entity): void
    {
        $servico = Servico::fromEntityType($entity->getEntityType());

        if (!$servico) {
            return;
        }

        if ($entity instanceof Agent && $this->isIndividualAgent($entity)) {
            return;
        }

        $this->marked[$entity] = $servico;
    }

    /** Entidade que nasceu publicada. */
    public function registerOnCreate(Entity $entity): void
    {
        $this->markForRegistration($entity);
        $this->registerMarked($entity);
    }

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

    /** Registra sem propagar exceção. */
    public function registerRequest(User $user, Servico $servico, ?Entity $entity): void
    {
        try {
            $this->registrar($user, $servico, $entity);
        } catch (\Throwable $e) {
            App::i()->log->error(sprintf(
                '[GovBrSatisfaction] falha ao registrar o serviço %s do usuário %d: %s',
                $servico->value,
                $user->id,
                $e->getMessage()
            ));
        }
    }

    private function registrar(User $user, Servico $servico, ?Entity $entity): void
    {
        $app = App::i();
        $config = $this->plugin->config;

        $subsite = $entity ? $entity->subsite : $app->getCurrentSubsite();

        $recusa = $this->plugin->subsiteRejectionReason($subsite);

        if ($recusa) {
            $app->log->debug("[GovBrSatisfaction] serviço concluído fora do portal atendido: {$recusa}");

            return;
        }

        $missing = $this->plugin->isDevMode() ? [] : $this->plugin->missingConfig();

        if ($missing) {
            $app->log->warning(sprintf(
                '[GovBrSatisfaction] serviço concluído não registrado: faltam %s',
                implode(', ', $missing)
            ));

            return;
        }

        $idServico = $config['servicos'][$servico->value] ?? '';

        if ($idServico === '') {
            $app->log->warning("[GovBrSatisfaction] serviço {$servico->value} sem id configurado; nada registrado");

            return;
        }

        // Uma por usuário e serviço.
        $existente = $app->repo(SatisfactionRequest::class)->findOneBy([
            'user' => $user,
            'servico' => $idServico,
        ]);

        if ($existente) {
            return;
        }

        if (!$app->em->isOpen()) {
            $app->log->error("[GovBrSatisfaction] unidade de trabalho fechada, serviço {$servico->value} não registrado");

            return;
        }

        $request = new SatisfactionRequest;
        $request->user = $user;
        $request->servico = $idServico;
        $request->subsite = $subsite;

        $request->objectType = $entity ? $entity->getEntityType() : null;
        $request->objectId = $entity ? $entity->id : null;

        $request->sendStatus = SatisfactionRequest::STATUS_PENDING;
        $request->etapa = $config['etapa'];
        $request->dataEtapa = new \DateTime();
        $request->situacaoEtapa = $config['situacaoEtapa'];
        $request->canalPrestacao = $config['canalPrestacao'];
        $request->canalAvaliacao = $config['canalAvaliacao'];
        $request->orgao = $config['orgao'] ?: null;

        $request->ipOrigem = $_SERVER['SERVER_ADDR'] ?? null;
        $request->ipUsuario = $this->ipUsuario();

        $app->disableAccessControl();

        try {
            $request->save(true);
        } finally {
            $app->enableAccessControl();
        }

        // Id constante: garante uma varredura na fila, sem empilhar. Sem
        // `replace` para não anular o adiamento marcado pelo job durante uma
        // queda do BSC.
        $app->enqueueJob(SendSatisfactionRequestJob::SLUG, []);
    }

    /** IP da requisição, ou nulo. */
    private function ipUsuario(): ?string
    {
        $request = App::i()->request;

        return $request ? self::primeiroIp($request->getIp()) : null;
    }

    /** Primeiro IP do cabeçalho, até 45 chars. */
    public static function primeiroIp(?string $valor): ?string
    {
        $ip = trim((string) strtok((string) $valor, ','));

        return $ip === '' ? null : mb_substr($ip, 0, 45);
    }

    private function isIndividualAgent(Agent $agent): bool
    {
        $tipo = $agent->type?->id;

        return $tipo !== null && (int) $tipo === (int) $this->plugin->config['agentTypeIndividual'];
    }
}
