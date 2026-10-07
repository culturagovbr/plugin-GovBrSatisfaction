<?php

namespace GovBrSatisfaction\Services;

use GovBrSatisfaction\Entities\SatisfactionAttempt;
use GovBrSatisfaction\Plugin;
use MapasCulturais\App;
use MapasCulturais\Entities\User;

/**
 * Janela de revelação do payload real, com registro de cada pedido.
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

    /** Janela aberta, com o motivo. */
    const ACTION_UNLOCK = 'liberar';

    const ACTION_REVEAL = 'revelar';

    const ACTION_COPY = 'copiar';

    /** Pedido recusado. */
    const ACTION_DENIED = 'negado';

    /** Hash da senha do login local (MultipleLocalAuth). */
    const PASSWORD_META = 'localAuthenticationPassword';

    const PASSWORD_OK = 'ok';

    const PASSWORD_WRONG = 'wrong';

    /** Conta sem senha local. */
    const PASSWORD_MISSING = 'missing';

    /** Senhas erradas seguidas até bloquear. */
    const PASSWORD_MAX_FAILS = 5;

    /** Segundos de bloqueio após as senhas erradas. */
    const PASSWORD_LOCK = 300;

    const FAILS_KEY = 'govbr-satisfaction.reveal-fails';

    public function __construct(private readonly Plugin $plugin)
    {
    }

    /** Confere a senha local do usuário. */
    public static function checkPassword(User $user, string $password): string
    {
        $hash = $user->getMetadata(self::PASSWORD_META);

        if (!is_string($hash) || $hash === '') {
            return self::PASSWORD_MISSING;
        }

        return $password !== '' && password_verify($password, $hash) ? self::PASSWORD_OK : self::PASSWORD_WRONG;
    }

    /** Fim do bloqueio por senhas erradas, ou nulo. */
    public function lockedUntil(User $user): ?int
    {
        $fails = $_SESSION[self::FAILS_KEY] ?? null;

        if (!is_array($fails) || (int) ($fails['user'] ?? 0) !== (int) $user->id || (int) ($fails['until'] ?? 0) <= time()) {
            return null;
        }

        return (int) $fails['until'];
    }

    /** Conta a senha errada; devolve o fim do bloqueio quando ele começa. */
    public function failPassword(User $user): ?int
    {
        $fails = $_SESSION[self::FAILS_KEY] ?? null;
        $count = is_array($fails) && (int) ($fails['user'] ?? 0) === (int) $user->id ? (int) ($fails['count'] ?? 0) : 0;
        $count++;

        if ($count < self::PASSWORD_MAX_FAILS) {
            $_SESSION[self::FAILS_KEY] = ['user' => (int) $user->id, 'count' => $count, 'until' => 0];

            return null;
        }

        $until = time() + self::PASSWORD_LOCK;
        $_SESSION[self::FAILS_KEY] = ['user' => (int) $user->id, 'count' => 0, 'until' => $until];

        return $until;
    }

    /** Abre a janela do usuário e devolve quando ela fecha. */
    public function unlock(User $user, string $reason): int
    {
        $until = time() + self::WINDOW;

        unset($_SESSION[self::FAILS_KEY]);

        $this->audit($user, self::ACTION_UNLOCK, null, ['motivo' => $reason]);

        $_SESSION[self::SESSION_KEY] = ['user' => (int) $user->id, 'until' => $until, 'reason' => $reason, 'count' => 0];

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

        $this->audit($user, $action, $attempt, ['motivo' => $_SESSION[self::SESSION_KEY]['reason'] ?? null]);

        $_SESSION[self::SESSION_KEY]['count'] = (int) ($_SESSION[self::SESSION_KEY]['count'] ?? 0) + 1;

        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Registra o pedido recusado. */
    public function deny(User $user, ?SatisfactionAttempt $attempt, string $why): void
    {
        $this->audit($user, self::ACTION_DENIED, $attempt, ['razao' => $why]);
    }

    /** Registra o pedido; uma falha no registro não interrompe a revelação. */
    private function audit(User $user, string $action, ?SatisfactionAttempt $attempt, array $data): void
    {
        $app = App::i();

        if ($attempt) {
            $data = ['envio' => $attempt->dispatch->uuid, 'tentativa' => (int) $attempt->number] + $data;
        }

        try {
            $this->plugin->revealAudit()->record($action, $data);
        } catch (\Throwable $e) {
            $app->log->error("[GovBrSatisfaction] falha ao registrar a revelação: {$e->getMessage()}");
        }

        $app->log->info(sprintf(
            '[GovBrSatisfaction] revelação: %s pelo usuário %d%s',
            $action,
            $user->id,
            $attempt ? " na tentativa {$attempt->id}" : ''
        ));
    }
}
