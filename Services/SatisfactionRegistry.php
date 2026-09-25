<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Bsc\Mask;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Jobs\SendSatisfactionRequestJob;
use GovBrSatisfaction\Plugin;
use GovBrSatisfaction\Service;
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
     * Entidades marcadas no gancho de status, aguardando o save:finish.
     *
     * @var \SplObjectStorage<Entity,Service>
     */
    private \SplObjectStorage $marked;

    public function __construct(private readonly Plugin $plugin)
    {
        $this->marked = new \SplObjectStorage;
    }

    public function markForRegistration(Entity $entity): void
    {
        $service = Service::fromEntityType($entity->getEntityType());

        if (!$service) {
            return;
        }

        if ($entity instanceof Agent && $this->isIndividualAgent($entity)) {
            return;
        }

        $this->marked[$entity] = $service;
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

        $service = $this->marked[$entity];
        unset($this->marked[$entity]);

        $user = $entity->ownerUser;

        if ($user instanceof User) {
            $this->registerRequest($user, $service, $entity);
        }
    }

    /** Registra sem propagar exceção. */
    public function registerRequest(User $user, Service $service, ?Entity $entity): void
    {
        try {
            $this->doRegister($user, $service, $entity);
        } catch (\Throwable $e) {
            App::i()->log->error(sprintf(
                '[GovBrSatisfaction] falha ao registrar o serviço %s do usuário %d: %s',
                $service->value,
                $user->id,
                Mask::forLogText($e->getMessage())
            ));
        }
    }

    private function doRegister(User $user, Service $service, ?Entity $entity): void
    {
        $app = App::i();
        $config = $this->plugin->config;

        $subsite = $entity ? $entity->subsite : $app->getCurrentSubsite();

        $reason = $this->plugin->subsiteRejectionReason($subsite);

        if ($reason) {
            $app->log->debug("[GovBrSatisfaction] serviço concluído fora do portal atendido: {$reason}");

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

        $serviceId = $config['servicos'][$service->value] ?? '';

        if ($serviceId === '') {
            $app->log->warning("[GovBrSatisfaction] serviço {$service->value} sem id configurado; nada registrado");

            return;
        }

        // Uma por usuário e serviço.
        $existing = $app->repo(SatisfactionRequest::class)->findOneBy([
            'user' => $user,
            'servico' => $serviceId,
        ]);

        if ($existing) {
            return;
        }

        if (!$app->em->isOpen()) {
            $app->log->error("[GovBrSatisfaction] unidade de trabalho fechada, serviço {$service->value} não registrado");

            return;
        }

        $request = new SatisfactionRequest;
        $request->user = $user;
        $request->servico = $serviceId;
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
        $request->ipUsuario = $this->userIp();

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
    private function userIp(): ?string
    {
        $request = App::i()->request;

        return $request ? self::firstIp($request->getIp()) : null;
    }

    /** Primeiro IP do cabeçalho, até 45 chars. */
    public static function firstIp(?string $value): ?string
    {
        $ip = trim((string) strtok((string) $value, ','));

        return $ip === '' ? null : mb_substr($ip, 0, 45);
    }

    private function isIndividualAgent(Agent $agent): bool
    {
        $type = $agent->type?->id;

        return $type !== null && (int) $type === (int) $this->plugin->config['agentTypeIndividual'];
    }
}
