<?php

namespace GovBrSatisfaction\Controllers;

use GovBrSatisfaction\Bsc\Mascara;
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
    const POR_PAGINA = 25;

    /**
     * Página de solicitações, com filtros e totais.
     *
     * @return void
     */
    public function GET_index()
    {
        $this->requireInstallationAdmin();

        $app = App::i();

        $pagina = max(1, (int) ($this->data['pagina'] ?? 1));

        $qb = $app->em->createQueryBuilder()
            ->from(SatisfactionRequest::class, 'r')
            ->join('r.user', 'u')
            ->leftJoin('u.profile', 'a');

        foreach (['situacao' => 'sendStatus', 'servico' => 'servico'] as $campo => $propriedade) {
            $valor = $this->data[$campo] ?? '';

            if (is_string($valor) && $valor !== '') {
                $qb->andWhere("r.{$propriedade} = :{$campo}")->setParameter($campo, $valor);
            }
        }

        $total = (int) (clone $qb)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        $registros = (clone $qb)
            ->select('r.id, r.servico, r.sendStatus, r.objectType, r.objectId,
                      r.createTimestamp, r.sendTimestamp, r.sendHttpStatus, r.sendDetail,
                      r.sendAttempts, u.id AS userId, u.email, a.name AS agente')
            ->orderBy('r.createTimestamp', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($pagina - 1) * self::POR_PAGINA)
            ->setMaxResults(self::POR_PAGINA)
            ->getQuery()
            ->getResult();

        // Totais sem filtro.
        $totais = [];

        $contagens = $app->em->createQueryBuilder()
            ->select('r.sendStatus AS situacao, COUNT(r.id) AS n')
            ->from(SatisfactionRequest::class, 'r')
            ->groupBy('r.sendStatus')
            ->getQuery()
            ->getResult();

        foreach ($contagens as $linha) {
            $totais[$linha['situacao']] = (int) $linha['n'];
        }

        $this->json([
            'registros' => array_map([$this, 'formatar'], $registros),
            'total' => $total,
            'pagina' => $pagina,
            'paginas' => (int) ceil($total / self::POR_PAGINA),
            'totais' => $totais,
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

        $enviado = $request->sendPayload ? json_decode($request->sendPayload, true) : null;

        if (is_array($enviado)) {
            $this->conteudo($request, payload: $enviado, reconstruido: false);

            return;
        }

        $cpf = Payload::cpf($request->user, $plugin->config['metadataFieldCPF']);

        if (!$cpf) {
            $this->conteudo($request, payload: null, reconstruido: true, motivo: \MapasCulturais\i::__('Sem CPF no cadastro: não há conteúdo a enviar.'));

            return;
        }

        $this->conteudo($request, payload: Payload::build($request, $cpf), reconstruido: true);
    }

    protected function conteudo(SatisfactionRequest $request, ?array $payload, bool $reconstruido, ?string $motivo = null): void
    {
        $this->json([
            'payload' => $payload === null ? null : Mascara::paraTela($payload),
            'reconstruido' => $reconstruido,
            'motivo' => $motivo,
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

        $servicos = [];

        foreach ($plugin->config['servicos'] as $chave => $id) {
            if ($id !== '') {
                $servicos[] = ['id' => (string) $id, 'chave' => $chave];
            }
        }

        $this->json([
            'devMode' => $plugin->isDevMode(),
            'faltando' => $plugin->missingConfig(),
            'servicos' => $servicos,
        ]);
    }

    /**
     * @param array $registro
     * @return array
     */
    protected function formatar(array $registro): array
    {
        $tipo = $registro['objectType'];
        $tipo = $tipo && str_contains($tipo, '\\') ? substr(strrchr($tipo, '\\'), 1) : $tipo;

        return [
            'id' => (int) $registro['id'],
            'servico' => (string) $registro['servico'],
            'situacao' => $registro['sendStatus'],
            'pessoa' => $registro['agente'] ?: $registro['email'],
            'userId' => (int) $registro['userId'],
            'origem' => $tipo ? $tipo . ' #' . (int) $registro['objectId'] : null,
            'registrada' => $registro['createTimestamp']->getTimestamp(),
            'disparada' => $registro['sendTimestamp']?->getTimestamp(),
            'httpStatus' => $registro['sendHttpStatus'] === null ? null : (int) $registro['sendHttpStatus'],
            'detalhe' => $registro['sendDetail'],
            'tentativas' => (int) $registro['sendAttempts'],
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
