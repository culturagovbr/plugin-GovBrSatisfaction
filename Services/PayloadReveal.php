<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Entities\SatisfactionAttempt;
use GovBrSatisfaction\Entities\SatisfactionReveal;
use GovBrSatisfaction\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Janela de revelação do payload real, com auditoria de cada pedido.
 *
 * @package GovBrSatisfaction
 */
class PayloadReveal
{
    /** Segundos da janela aberta pelo motivo. */
    const WINDOW = 120;

    /** Tamanho mínimo do motivo. */
    const REASON_MIN = 10;

    /** Revelações e cópias por janela. */
    const WINDOW_LIMIT = 10;

    const SESSION_KEY = 'govbr-satisfaction.reveal';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /** Abre a janela do usuário e devolve quando ela fecha. */
    public function unlock(User $user, string $reason): int
    {
        $until = time() + self::WINDOW;

        $_SESSION[self::SESSION_KEY] = ['user' => (int) $user->id, 'until' => $until, 'reason' => $reason, 'count' => 0];

        $this->audit($user, SatisfactionReveal::ACTION_UNLOCK, null, $reason);

        return $until;
    }

    /** Fim da janela aberta do usuário, ou nulo. */
    public function windowUntil(User $user): ?int
    {
        $window = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($window) || (int) ($window['user'] ?? 0) !== (int) $user->id || (int) ($window['until'] ?? 0) <= time()) {
            return null;
        }

        return (int) $window['until'];
    }

    /** Revelações que ainda cabem na janela aberta. */
    public function remaining(): int
    {
        return max(0, self::WINDOW_LIMIT - (int) ($_SESSION[self::SESSION_KEY]['count'] ?? 0));
    }

    /** Fecha a janela do usuário. */
    public function close(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /** Payload real da tentativa, registrando a ação com o motivo da janela. */
    public function reveal(User $user, SatisfactionAttempt $attempt, string $action): array
    {
        $vault = $this->plugin->vault();

        if (!$vault || $attempt->payloadSealed === null) {
            throw new \RuntimeException('conteúdo real indisponível');
        }

        $plain = $vault->open(
            $attempt->payloadSealed,
            PayloadVault::context($attempt->dispatch->uuid, (int) $attempt->number)
        );

        $this->audit($user, $action, $attempt, $_SESSION[self::SESSION_KEY]['reason'] ?? null);

        $_SESSION[self::SESSION_KEY]['count'] = (int) ($_SESSION[self::SESSION_KEY]['count'] ?? 0) + 1;

        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Registra o pedido recusado. */
    public function deny(User $user, ?SatisfactionAttempt $attempt, string $why): void
    {
        $this->audit($user, SatisfactionReveal::ACTION_DENIED, $attempt, $why);
    }

    private function audit(User $user, string $action, ?SatisfactionAttempt $attempt, ?string $reason): void
    {
        $app = App::i();
        $request = $app->request;

        $entry = new SatisfactionReveal();
        $entry->userId = (int) $user->id;
        $entry->action = $action;
        $entry->attemptId = $attempt ? (int) $attempt->id : null;
        $entry->requestId = $attempt ? (int) $attempt->dispatch->request->id : null;
        $entry->reason = $reason;
        $entry->ip = $request ? SatisfactionRegistry::firstIp($request->getIp()) : null;
        $entry->userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;

        $app->em->persist($entry);
        $app->em->flush();

        $app->log->info(sprintf(
            '[GovBrSatisfaction] revelação: %s pelo usuário %d%s',
            $action,
            $user->id,
            $attempt ? " na tentativa {$attempt->id}" : ''
        ));
    }
}
