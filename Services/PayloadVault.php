<?php

namespace GovBrSatisfaction\Services;

use MapasCulturais\App;

/**
 * Cifra e decifra o payload real com XChaCha20-Poly1305.
 *
 * @package GovBrSatisfaction
 */
final class PayloadVault
{
    /**
     * @param array<int, string> $keys Chave binária por versão
     */
    public function __construct(private readonly array $keys)
    {
        if (!$keys) {
            throw new \InvalidArgumentException('chaveiro vazio');
        }

        foreach ($keys as $version => $key) {
            if (!is_int($version) || $version < 1 || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new \InvalidArgumentException('chave inválida');
            }
        }
    }

    /** Cofre a partir de "versão:base64" separados por vírgula; nulo se vazio ou inválido. */
    public static function fromConfig(string $keyring): ?self
    {
        $keys = [];

        foreach (array_filter(array_map('trim', explode(',', $keyring))) as $entry) {
            [$version, $encoded] = array_pad(explode(':', $entry, 2), 2, '');
            $key = base64_decode($encoded, true);

            if (!ctype_digit($version) || (int) $version < 1 || $key === false
                || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                App::i()->log->error('[GovBrSatisfaction] AVALIACAO_CHAVES_PAYLOAD inválida; o payload real não será guardado');

                return null;
            }

            $keys[(int) $version] = $key;
        }

        return $keys ? new self($keys) : null;
    }

    /** Contexto que amarra o texto cifrado à tentativa. */
    public static function context(string $dispatchUuid, int $number): string
    {
        return "govbr-satisfaction:{$dispatchUuid}:{$number}";
    }

    /** Cifra com a chave mais nova. */
    public function seal(string $plain, string $context): string
    {
        $version = max(array_keys($this->keys));
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, $context, $nonce, $this->keys[$version]);

        return "v{$version}:" . base64_encode($nonce . $cipher);
    }

    /** Decifra; lança com versão desconhecida, contexto diferente ou texto alterado. */
    public function open(string $sealed, string $context): string
    {
        if (!preg_match('/^v(\d+):(.+)$/s', $sealed, $match) || !isset($this->keys[(int) $match[1]])) {
            throw new \RuntimeException('versão de chave desconhecida');
        }

        $raw = base64_decode($match[2], true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($raw === false || strlen($raw) <= $nonceLength) {
            throw new \RuntimeException('texto cifrado inválido');
        }

        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nonceLength),
            $context,
            substr($raw, 0, $nonceLength),
            $this->keys[(int) $match[1]]
        );

        if ($plain === false) {
            throw new \RuntimeException('não foi possível decifrar');
        }

        return $plain;
    }
}
