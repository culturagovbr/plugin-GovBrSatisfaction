<?php

namespace GovBrSatisfaction\Controllers;

use GovBrSatisfaction\Bsc\Mask;
use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Entities\SatisfactionAttempt;
use GovBrSatisfaction\Entities\SatisfactionDispatch;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use GovBrSatisfaction\Services\DispatchLog;
use MapasCulturais\App;

/**
 * Painel de solicitações: listagem, conteúdo enviado, histórico de envios, devolver à fila.
 *
 * @package GovBrSatisfaction
 */
class Requests extends \MapasCulturais\Controller
{
    const PER_PAGE = 25;

    /** Envios por página do histórico. */
    const DISPATCHES_PER_PAGE = 20;

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

        $search = $this->data['busca'] ?? '';

        if (is_string($search) && trim($search) !== '') {
            $this->applySearch($qb, trim($search));
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

    /** Busca por uuid do envio, id do usuário ou nome do agente. */
    protected function applySearch(\Doctrine\ORM\QueryBuilder $qb, string $search): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $search)) {
            $qb->andWhere('EXISTS (SELECT d.id FROM ' . SatisfactionDispatch::class . ' d WHERE d.request = r AND d.uuid = :uuid)')
                ->setParameter('uuid', strtolower($search));

            return;
        }

        if (ctype_digit($search)) {
            $qb->andWhere('u.id = :userId')->setParameter('userId', (int) $search);

            return;
        }

        $qb->andWhere('LOWER(a.name) LIKE :name')
            ->setParameter('name', '%' . addcslashes(mb_strtolower($search), '%_\\') . '%');
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

        $attempt = (new DispatchLog())->lastAttempt($request->id);
        $sent = $attempt?->payload ? json_decode($attempt->payload, true) : null;

        if (is_array($sent)) {
            $this->respondContent(payload: $sent, preview: false, response: $attempt->response);

            return;
        }

        $cpf = Payload::cpf($request->user, $plugin->config['metadataFieldCPF']);

        if (!$cpf) {
            $this->respondContent(payload: null, preview: true, reason: \MapasCulturais\i::__('Sem CPF no cadastro: não há conteúdo a enviar.'));

            return;
        }

        $this->respondContent(payload: Payload::build($request, $cpf), preview: true, response: $attempt?->response);
    }

    protected function respondContent(?array $payload, bool $preview, ?string $reason = null, ?string $response = null): void
    {
        $this->json([
            'payload' => $payload === null ? null : Mask::forScreen($payload),
            'reconstruido' => $preview,
            'motivo' => $reason,
            'resposta' => $response === null ? null : Mask::forBody($response),
        ]);
    }

    /**
     * Envios de uma solicitação, do mais novo ao mais antigo, com as tentativas.
     *
     * @return void
     */
    public function GET_dispatches()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $request = $app->repo(SatisfactionRequest::class)->find((int) ($this->data['id'] ?? 0));

        if (!$request) {
            $this->json(['error' => \MapasCulturais\i::__('Solicitação não encontrada.')], 404);

            return;
        }

        $log = new DispatchLog();
        $page = max(1, (int) ($this->data['pagina'] ?? 1));
        $total = $log->countByRequest($request->id);
        $rows = $log->findByRequest(
            $request->id,
            ($page - 1) * self::DISPATCHES_PER_PAGE,
            self::DISPATCHES_PER_PAGE
        );

        $this->json([
            'envios' => array_map([$this, 'formatDispatch'], $rows),
            'total' => $total,
            'pagina' => $page,
            'paginas' => (int) ceil($total / self::DISPATCHES_PER_PAGE),
        ]);
    }

    /** Envio no formato da tela. */
    protected function formatDispatch(array $row): array
    {
        $dispatch = $row['dispatch'];

        return [
            'uuid' => $dispatch->uuid,
            'situacao' => $dispatch->state,
            'origem' => $dispatch->origin,
            'autor' => $row['userId'] === null ? null : ['id' => $row['userId']],
            'criadoEm' => $dispatch->createTimestamp->getTimestamp(),
            'finalizadoEm' => $dispatch->finishTimestamp?->getTimestamp(),
            'tentativas' => array_map([$this, 'formatAttempt'], $row['attempts']),
        ];
    }

    /** Tentativa no formato da tela, mascarada. */
    protected function formatAttempt(SatisfactionAttempt $attempt): array
    {
        $payload = $attempt->payload === null ? null : json_decode($attempt->payload, true);

        return [
            'numero' => (int) $attempt->number,
            'maximo' => (int) $attempt->maxAttempts,
            'situacao' => $attempt->outcome,
            'metodo' => $attempt->method,
            'endpoint' => $attempt->endpoint,
            'httpStatus' => $attempt->httpStatus === null ? null : (int) $attempt->httpStatus,
            'detalhe' => $attempt->detail === null ? null : Mask::forLogText($attempt->detail),
            'payload' => is_array($payload) ? Mask::forScreen($payload) : null,
            'resposta' => $attempt->response === null ? null : Mask::forBody($attempt->response),
            'respostaCortada' => (bool) $attempt->responseTruncated,
            'cabecalhos' => $attempt->responseHeaders === null
                ? null
                : array_map(fn($line) => Mask::forLogText((string) $line), $attempt->responseHeaders),
            'enviadoEm' => $attempt->sentAt->getTimestamp(),
            'duracaoMs' => $attempt->durationMs === null ? null : (int) $attempt->durationMs,
        ];
    }

    /** Situações que podem voltar à fila. */
    const REQUEUE_STATUSES = [
        SatisfactionRequest::STATUS_REJECTED,
        SatisfactionRequest::STATUS_NO_CPF,
    ];

    /**
     * Devolve à fila (recusada, sem CPF) ou antecipa a tentativa (pendente com falha).
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

        $waitingRetry = $request->sendStatus === SatisfactionRequest::STATUS_PENDING
            && $request->sendDetail !== null;

        if ($waitingRetry) {
            $this->plugin()->sender()->retryNow($request, $app->user);
        } elseif (in_array($request->sendStatus, self::REQUEUE_STATUSES, true)) {
            $this->plugin()->sender()->requeue($request, $app->user);
        } else {
            $this->json(['error' => \MapasCulturais\i::__('Só solicitações recusadas, sem CPF ou pendentes com falha podem voltar à fila.')], 400);

            return;
        }

        $this->json([
            'id' => $request->id,
            'situacao' => $request->sendStatus,
            'tentativas' => (int) $request->sendAttempts,
            'disparada' => null,
        ]);
    }

    /** Teto por clique. */
    const BULK_MAX = 500;

    /**
     * Devolve à fila as recusadas do filtro, com jobs escalonados.
     *
     * @return void
     */
    public function POST_requeueAll()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $qb = $app->em->createQueryBuilder()
            ->select('r')
            ->from(SatisfactionRequest::class, 'r')
            ->where('r.sendStatus = :recusado')
            ->setParameter('recusado', SatisfactionRequest::STATUS_REJECTED);

        $service = $this->data['servico'] ?? '';

        if (is_string($service) && $service !== '') {
            $qb->andWhere('r.servico = :servico')->setParameter('servico', $service);
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $requests = $qb
            ->orderBy('r.createTimestamp', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->setMaxResults(self::BULK_MAX)
            ->getQuery()
            ->getResult();

        $requeued = $this->plugin()->sender()->requeueMany($requests, $app->user);

        $this->json([
            'devolvidas' => $requeued,
            'restantes' => max(0, $total - $requeued),
            'intervalo' => \GovBrSatisfaction\Jobs\SendSatisfactionRequestJob::BULK_INTERVAL,
        ]);
    }

    /**
     * Devolve à fila as recusadas e sem CPF selecionadas, com jobs escalonados.
     *
     * @return void
     */
    public function POST_requeueSelected()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $ids = $this->selectedIds();

        if ($ids === null) {
            $this->json(['error' => \MapasCulturais\i::__('Seleção inválida.')], 400);

            return;
        }

        if (!$ids) {
            $this->json(['error' => \MapasCulturais\i::__('Nenhuma solicitação selecionada.')], 400);

            return;
        }

        if (count($ids) > self::BULK_MAX) {
            $this->json(['error' => sprintf(\MapasCulturais\i::__('Selecione no máximo %d por vez.'), self::BULK_MAX)], 400);

            return;
        }

        $requests = $app->em->createQueryBuilder()
            ->select('r')
            ->from(SatisfactionRequest::class, 'r')
            ->where('r.id IN (:ids)')
            ->andWhere('r.sendStatus IN (:statuses)')
            ->setParameter('ids', $ids)
            ->setParameter('statuses', self::REQUEUE_STATUSES)
            ->orderBy('r.createTimestamp', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        $requeued = $this->plugin()->sender()->requeueMany($requests, $app->user);

        $this->json([
            'devolvidas' => $requeued,
            'ignoradas' => count($ids) - $requeued,
            'intervalo' => \GovBrSatisfaction\Jobs\SendSatisfactionRequestJob::BULK_INTERVAL,
        ]);
    }

    /** Ids da seleção, sem repetição; nulo quando algum é inválido. */
    protected function selectedIds(): ?array
    {
        $raw = $this->data['ids'] ?? [];

        if (is_string($raw)) {
            $raw = trim($raw) === '' ? [] : explode(',', $raw);
        }

        if (!is_array($raw)) {
            return null;
        }

        $ids = [];

        foreach ($raw as $value) {
            if (!is_int($value) && !is_string($value)) {
                return null;
            }

            $id = filter_var(trim((string) $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id === false) {
                return null;
            }

            $ids[$id] = $id;
        }

        return array_values($ids);
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
            'loteIntervalo' => \GovBrSatisfaction\Jobs\SendSatisfactionRequestJob::BULK_INTERVAL,
            'loteMaximo' => self::BULK_MAX,
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
            'pessoa' => $record['agente'] ?: ($record['email'] ? Mask::email($record['email']) : null),
            'userId' => (int) $record['userId'],
            'origem' => $type ? $type . ' #' . (int) $record['objectId'] : null,
            'registrada' => $record['createTimestamp']->getTimestamp(),
            'disparada' => $record['sendTimestamp']?->getTimestamp(),
            'httpStatus' => $record['sendHttpStatus'] === null ? null : (int) $record['sendHttpStatus'],
            'detalhe' => $record['sendDetail'] === null ? null : Mask::forLogText($record['sendDetail']),
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
