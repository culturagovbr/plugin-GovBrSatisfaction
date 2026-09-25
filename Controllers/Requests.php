<?php

namespace GovBrSatisfaction\Controllers;

use GovBrSatisfaction\Bsc\Mask;
use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use MapasCulturais\App;

/**
 * Painel de solicitações: listagem, conteúdo enviado, devolver à fila.
 *
 * @package GovBrSatisfaction
 */
class Requests extends \MapasCulturais\Controller
{
    const PER_PAGE = 25;

    /**
     * Página de solicitações, com filtros e totais.
     *
     * @return void
     */
    public function GET_index()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $page = max(1, (int) ($this->data['pagina'] ?? 1));

        $qb = $app->em->createQueryBuilder()
            ->from(SatisfactionRequest::class, 'r')
            ->join('r.user', 'u')
            ->leftJoin('u.profile', 'a');

        foreach (['situacao' => 'sendStatus', 'servico' => 'servico'] as $field => $property) {
            $value = $this->data[$field] ?? '';

            if (is_string($value) && $value !== '') {
                $qb->andWhere("r.{$property} = :{$field}")->setParameter($field, $value);
            }
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $records = (clone $qb)
            ->select('r.id, r.servico, r.sendStatus, r.objectType, r.objectId,
                      r.createTimestamp, r.sendTimestamp, r.sendHttpStatus, r.sendDetail,
                      r.sendAttempts, u.id AS userId, u.email, a.name AS agente')
            ->orderBy('r.createTimestamp', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * self::PER_PAGE)
            ->setMaxResults(self::PER_PAGE)
            ->getQuery()
            ->getResult();

        // Totais sem filtro.
        $totals = [];

        $counts = $app->em->createQueryBuilder()
            ->select('r.sendStatus AS situacao, COUNT(r.id) AS n')
            ->from(SatisfactionRequest::class, 'r')
            ->groupBy('r.sendStatus')
            ->getQuery()
            ->getResult();

        foreach ($counts as $row) {
            $totals[$row['situacao']] = (int) $row['n'];
        }

        $this->json([
            'registros' => array_map([$this, 'formatar'], $records),
            'total' => $total,
            'pagina' => $page,
            'paginas' => (int) ceil($total / self::PER_PAGE),
            'totais' => $totals,
        ]);
    }

    /**
     * Conteúdo enviado (cópia ou prévia) e resposta do BSC, mascarados.
     *
     * @return void
     */
    public function GET_payload()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $request = $app->repo(SatisfactionRequest::class)->find((int) ($this->data['id'] ?? 0));

        if (!$request) {
            $this->json(['error' => \MapasCulturais\i::__('Solicitação não encontrada.')], 404);

            return;
        }

        $plugin = $this->plugin();

        $sent = $request->sendPayload ? json_decode($request->sendPayload, true) : null;

        if (is_array($sent)) {
            $this->respondContent($request, payload: $sent, preview: false);

            return;
        }

        $cpf = Payload::cpf($request->user, $plugin->config['metadataFieldCPF']);

        if (!$cpf) {
            $this->respondContent($request, payload: null, preview: true, reason: \MapasCulturais\i::__('Sem CPF no cadastro: não há conteúdo a enviar.'));

            return;
        }

        $this->respondContent($request, payload: Payload::build($request, $cpf), preview: true);
    }

    protected function respondContent(SatisfactionRequest $request, ?array $payload, bool $preview, ?string $reason = null): void
    {
        $this->json([
            'payload' => $payload === null ? null : Mask::forScreen($payload),
            'reconstruido' => $preview,
            'motivo' => $reason,
            'resposta' => $request->sendResponse,
        ]);
    }

    /** Situações que podem voltar à fila. */
    const REQUEUE_STATUSES = [
        SatisfactionRequest::STATUS_REJECTED,
        SatisfactionRequest::STATUS_NO_CPF,
    ];

    /**
     * Devolve à fila uma solicitação recusada ou sem CPF. Não altera regra
     * nenhuma, só opera a fila.
     *
     * @return void
     */
    public function POST_requeue()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $request = $app->repo(SatisfactionRequest::class)->find((int) ($this->data['id'] ?? 0));

        if (!$request) {
            $this->json(['error' => \MapasCulturais\i::__('Solicitação não encontrada.')], 404);

            return;
        }

        // Enviada já foi; pendente já está na fila.
        if (!in_array($request->sendStatus, self::REQUEUE_STATUSES, true)) {
            $this->json(['error' => \MapasCulturais\i::__('Só solicitações recusadas ou sem CPF podem voltar à fila.')], 400);

            return;
        }

        $this->plugin()->sender()->requeue($request, $app->user);

        $this->json([
            'id' => $request->id,
            'situacao' => $request->sendStatus,
            'tentativas' => 0,
            'disparada' => null,
        ]);
    }

    /**
     * Configuração vigente, para os avisos da tela.
     *
     * @return void
     */
    public function GET_status()
    {
        $this->requireInstallationAdmin();

        $plugin = $this->plugin();

        $services = [];

        foreach ($plugin->config['servicos'] as $key => $id) {
            if ($id !== '') {
                $services[] = ['id' => (string) $id, 'chave' => $key];
            }
        }

        $this->json([
            'devMode' => $plugin->isDevMode(),
            'faltando' => $plugin->missingConfig(),
            'servicos' => $services,
        ]);
    }

    /**
     * @param array $record
     * @return array
     */
    protected function formatar(array $record): array
    {
        $type = $record['objectType'];
        $type = $type && str_contains($type, '\\') ? substr(strrchr($type, '\\'), 1) : $type;

        return [
            'id' => (int) $record['id'],
            'servico' => (string) $record['servico'],
            'situacao' => $record['sendStatus'],
            'pessoa' => $record['agente'] ?: $record['email'],
            'userId' => (int) $record['userId'],
            'origem' => $type ? $type . ' #' . (int) $record['objectId'] : null,
            'registrada' => $record['createTimestamp']->getTimestamp(),
            'disparada' => $record['sendTimestamp']?->getTimestamp(),
            'httpStatus' => $record['sendHttpStatus'] === null ? null : (int) $record['sendHttpStatus'],
            'detalhe' => $record['sendDetail'],
            'tentativas' => (int) $record['sendAttempts'],
        ];
    }

    /** Admin da instalação, plugin ligado e portal atendido. */
    protected function requireInstallationAdmin(): void
    {
        $app = App::i();

        $this->requireAuthentication();

        if (!$app->user->is(\GovBrSatisfaction\Plugin::ADMIN_ROLE)) {
            $app->halt(403, \MapasCulturais\i::__('Acesso restrito.'));
        }

        $this->plugin();
    }

    protected function plugin(): \GovBrSatisfaction\Plugin
    {
        $plugin = \GovBrSatisfaction\Plugin::instance();

        if (!$plugin || !$plugin->config['enabled']) {
            $this->json(['error' => \MapasCulturais\i::__('Plugin desligado.')], 503);
        }

        if (!$plugin->isPanelSubsite()) {
            $this->json(['error' => \MapasCulturais\i::__('Não encontrado.')], 404);
        }

        return $plugin;
    }
}
