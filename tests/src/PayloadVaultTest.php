<?php

namespace Tests\GovBrSatisfaction;

use GovBrSatisfaction\Services\PayloadVault;

/** Cofre do payload real. */
class PayloadVaultTest extends TestCase
{
    const CONTEUDO = '{"cpfCidadao":"77689062768","email":"maria.silva@example.com"}';

    protected static function chave(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
    }

    function testCifraEDecifra()
    {
        $cofre = PayloadVault::fromConfig('1:' . self::chave());
        $cifrado = $cofre->seal(self::CONTEUDO, 'contexto');

        $this->assertStringStartsWith('v1:', $cifrado);
        $this->assertStringNotContainsString(self::CPF, $cifrado);
        $this->assertSame(self::CONTEUDO, $cofre->open($cifrado, 'contexto'));
    }

    function testMesmoConteudoCifraDiferente()
    {
        $cofre = PayloadVault::fromConfig('1:' . self::chave());

        $this->assertNotSame($cofre->seal(self::CONTEUDO, 'contexto'), $cofre->seal(self::CONTEUDO, 'contexto'));
    }

    /** @dataProvider aberturasRecusadas */
    function testNaoAbreForaDoLugar(callable $adulterar)
    {
        $chave = self::chave();
        $cofre = PayloadVault::fromConfig("1:{$chave}");
        [$cifrado, $contexto, $outroCofre] = $adulterar($cofre->seal(self::CONTEUDO, 'contexto'), $chave);

        $this->expectException(\RuntimeException::class);

        ($outroCofre ?? $cofre)->open($cifrado, $contexto);
    }

    public static function aberturasRecusadas(): array
    {
        return [
            'outro contexto' => [fn($c) => [$c, 'outra tentativa', null]],
            'outra chave' => [fn($c) => [$c, 'contexto', PayloadVault::fromConfig('1:' . self::chave())]],
            'texto alterado' => [fn($c) => [substr($c, 0, -4) . 'AAAA', 'contexto', null]],
            'versão desconhecida' => [fn($c) => ['v9' . substr($c, 2), 'contexto', null]],
            'sem prefixo' => [fn($c) => [substr($c, 3), 'contexto', null]],
        ];
    }

    /** Chave nova cifra; a antiga continua abrindo o que já foi gravado. */
    function testRotacaoMantemOAntigo()
    {
        $antiga = self::chave();
        $cifradoAntes = PayloadVault::fromConfig("1:{$antiga}")->seal(self::CONTEUDO, 'contexto');

        $cofre = PayloadVault::fromConfig("1:{$antiga}, 2:" . self::chave());

        $this->assertSame(self::CONTEUDO, $cofre->open($cifradoAntes, 'contexto'));
        $this->assertStringStartsWith('v2:', $cofre->seal(self::CONTEUDO, 'contexto'));
    }

    /** @dataProvider chaveirosInvalidos */
    function testChaveiroInvalidoNaoCriaCofre(string $chaveiro)
    {
        $this->assertNull(PayloadVault::fromConfig($chaveiro));
    }

    public static function chaveirosInvalidos(): array
    {
        return [
            'vazio' => [''],
            'chave curta' => ['1:' . base64_encode('curta')],
            'versão zero' => ['0:' . base64_encode(str_repeat('a', 32))],
            'versão não numérica' => ['x:' . base64_encode(str_repeat('a', 32))],
            'base64 inválido' => ['1:%%%'],
        ];
    }
}
