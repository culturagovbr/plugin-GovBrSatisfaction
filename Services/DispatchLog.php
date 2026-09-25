<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Bsc\Mask;
use GovBrSatisfaction\Bsc\Payload;
use GovBrSatisfaction\Bsc\Result;
use GovBrSatisfaction\Entities\SatisfactionAttempt;
use GovBrSatisfaction\Entities\SatisfactionDispatch;
use GovBrSatisfaction\Entities\SatisfactionRequest;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Histórico de envios e tentativas de cada solicitação.
 *
 * @package GovBrSatisfaction
 */
class DispatchLog
{
    /** Teto da resposta gravada, em bytes. */
    const RESPONSE_MAX = 65536;

    /** Sem cofre explícito, usa o do plugin a cada gravação. */
    public function __construct(private readonly ?PayloadVault $vault = null)
    {
    }

    /** Abre um envio e marca como substituídos os pendentes da mesma solicitação. */
    public function start(SatisfactionRequest $request, string $origin, ?User $user = null): SatisfactionDispatch
    {
        $app = App::i();

        $dispatch = new SatisfactionDispatch();
        $dispatch->request = $app->em->getReference(SatisfactionRequest::class, $request->id);
        $dispatch->uuid = self::uuid();
        $dispatch->origin = $origin;
        $dispatch->user = $user ? $app->em->getReference(User::class, $user->id) : null;

        $this->persist($dispatch);

        $this->replacePending($request, $dispatch);

        return $dispatch;
    }

    /** Envio em curso da solicitação. */
    public function pending(SatisfactionRequest $request): ?SatisfactionDispatch
    {
        return App::i()->repo(SatisfactionDispatch::class)->findOneBy(
            ['request' => $request, 'state' => SatisfactionDispatch::STATE_PENDING],
            ['createTimestamp' => 'DESC', 'id' => 'DESC']
        );
    }

    /** Última tentativa da solicitação, em qualquer envio. */
    public function lastAttempt(int $requestId): ?SatisfactionAttempt
    {
        return App::i()->em->createQuery(
            'SELECT t
               FROM ' . SatisfactionAttempt::class . ' t
               JOIN t.dispatch d
              WHERE IDENTITY(d.request) = :request
           ORDER BY t.sentAt DESC, t.id DESC'
        )
            ->setParameter('request', $requestId)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /** Executa uma gravação do histórico; a falha vai para o log e devolve nulo. */
    public function guard(callable $write): mixed
    {
        $app = App::i();

        if (!$app->em->isOpen()) {
            return null;
        }

        try {
            return $write();
        } catch (\Throwable $e) {
            $app->log->error(sprintf(
                '[GovBrSatisfaction] falha ao gravar o histórico de envios: %s',
                Mask::forLogText($e->getMessage())
            ));

            return null;
        }
    }

    /** Envio pelo uuid. */
    public function find(string $uuid): ?SatisfactionDispatch
    {
        return App::i()->repo(SatisfactionDispatch::class)->findOneBy(['uuid' => $uuid]);
    }

    /** Grava uma tentativa, mascarando payload, resposta, cabeçalhos e resumo; cifra o payload real se houver cofre. */
    public function recordAttempt(
        SatisfactionDispatch $dispatch,
        int $number,
        int $maxAttempts,
        string $outcome,
        ?array $payload = null,
        ?int $httpStatus = null,
        ?string $response = null,
        ?string $detail = null,
        ?string $method = null,
        ?string $endpoint = null,
        ?array $responseHeaders = null,
        ?int $durationMs = null,
        ?\DateTime $sentAt = null,
    ): SatisfactionAttempt {
        $app = App::i();

        $attempt = new SatisfactionAttempt();
        $attempt->dispatch = $app->em->getReference(SatisfactionDispatch::class, $dispatch->id);
        $attempt->number = $number;
        $attempt->maxAttempts = $maxAttempts;
        $attempt->outcome = $outcome;
        $attempt->method = $method;
        $attempt->endpoint = $endpoint;
        $attempt->httpStatus = $httpStatus;
        $known = $payload === null ? [] : Mask::personalValues($payload);
        $attempt->payload = $payload === null ? null : Payload::encode(Mask::forScreen($payload));
        $vault = $this->vault ?? \GovBrSatisfaction\Plugin::instance()?->vault();
        $attempt->payloadSealed = $payload === null || !$vault
            ? null
            : $vault->seal(Payload::encode($payload), PayloadVault::context($dispatch->uuid, $number));
        $attempt->detail = $detail === null
            ? null
            : mb_substr(Mask::forLogText(self::utf8($detail), $known), 0, Result::DETAIL_MAX);
        $attempt->responseHeaders = $responseHeaders === null
            ? null
            : array_values(array_map(fn($line) => Mask::forLogText(self::utf8((string) $line), $known), $responseHeaders));
        $attempt->sentAt = $sentAt ?? new \DateTime();
        $attempt->durationMs = $durationMs;

        if ($response !== null) {
            $masked = Mask::forBody(self::utf8($response), $known);
            $attempt->responseTruncated = strlen($masked) > self::RESPONSE_MAX;
            $attempt->response = $attempt->responseTruncated
                ? mb_strcut($masked, 0, self::RESPONSE_MAX, 'UTF-8')
                : $masked;
        }

        $this->persist($attempt);

        return $attempt;
    }

    /** Apaga o payload cifrado e a resposta das tentativas anteriores à data; devolve quantas. */
    public function purge(\DateTime $before): int
    {
        return (int) App::i()->em->createQuery(
            'UPDATE ' . SatisfactionAttempt::class . ' t
                SET t.payloadSealed = NULL, t.response = NULL, t.responseTruncated = false
              WHERE t.sentAt < :before
                AND (t.payloadSealed IS NOT NULL OR t.response IS NOT NULL)'
        )->execute(['before' => $before]);
    }

    /** Encerra o envio se ainda estiver pendente; devolve se encerrou. */
    public function finish(SatisfactionDispatch $dispatch, string $state): bool
    {
        $app = App::i();

        $changed = $app->em->createQuery(
            'UPDATE ' . SatisfactionDispatch::class . ' d
                SET d.state = :state, d.finishTimestamp = :now
              WHERE d.id = :id AND d.state = :pending'
        )->execute([
            'state' => $state,
            'now' => new \DateTime(),
            'id' => $dispatch->id,
            'pending' => SatisfactionDispatch::STATE_PENDING,
        ]);

        $app->em->refresh($dispatch);

        return $changed > 0;
    }

    /**
     * Envios da solicitação, do mais novo ao mais antigo, com as tentativas em ordem.
     *
     * @return array<array{dispatch: SatisfactionDispatch, userId: int|null, attempts: SatisfactionAttempt[]}>
     */
    public function findByRequest(int $requestId, int $skip = 0, int $limit = 20): array
    {
        $app = App::i();

        $rows = $app->em->createQuery(
            'SELECT d, IDENTITY(d.user) AS userId
               FROM ' . SatisfactionDispatch::class . ' d
              WHERE IDENTITY(d.request) = :request
           ORDER BY d.createTimestamp DESC, d.id DESC'
        )
            ->setParameter('request', $requestId)
            ->setFirstResult($skip)
            ->setMaxResults($limit)
            ->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)
            ->getResult();

        if (!$rows) {
            return [];
        }

        $dispatches = array_map(fn($row) => $row[0], $rows);

        $attempts = [];
        $found = $app->repo(SatisfactionAttempt::class)->findBy(
            ['dispatch' => $dispatches],
            ['number' => 'ASC', 'id' => 'ASC']
        );

        foreach ($found as $attempt) {
            $attempts[$attempt->dispatch->id][] = $attempt;
        }

        return array_map(fn($row) => [
            'dispatch' => $row[0],
            'userId' => $row['userId'] === null ? null : (int) $row['userId'],
            'attempts' => $attempts[$row[0]->id] ?? [],
        ], $rows);
    }

    public function countByRequest(int $requestId): int
    {
        return App::i()->repo(SatisfactionDispatch::class)->count(['request' => $requestId]);
    }

    /** Grava a entidade; na falha, tira da unidade de trabalho. */
    private function persist(object $entity): void
    {
        $em = App::i()->em;

        try {
            $em->persist($entity);
            $em->flush();
        } catch (\Throwable $e) {
            if ($em->isOpen() && $em->contains($entity)) {
                $em->detach($entity);
            }

            throw $e;
        }
    }

    /** Marca como substituídos os pendentes anteriores da solicitação. */
    private function replacePending(SatisfactionRequest $request, SatisfactionDispatch $current): void
    {
        App::i()->em->createQuery(
            'UPDATE ' . SatisfactionDispatch::class . ' d
                SET d.state = :replaced, d.finishTimestamp = :now
              WHERE d.request = :request AND d.state = :pending AND d.id <> :current'
        )->execute([
            'replaced' => SatisfactionDispatch::STATE_REPLACED,
            'now' => new \DateTime(),
            'request' => $request->id,
            'pending' => SatisfactionDispatch::STATE_PENDING,
            'current' => $current->id,
        ]);
    }

    /** Troca bytes inválidos por um caractere substituto. */
    private static function utf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }

    /** UUID v4. */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
