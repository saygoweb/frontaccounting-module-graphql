<?php

namespace FA\GraphQL\Tests\Unit;

use FA\GraphQL\Config;
use FA\GraphQL\ConfigException;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private const SECRET = '0123456789abcdef0123456789abcdef';

    public function testDefaults(): void
    {
        $config = Config::fromArray(['secret' => self::SECRET]);

        $this->assertSame('fa-graphql', $config->issuer);
        $this->assertSame(900, $config->accessTtl);
        $this->assertSame(2592000, $config->refreshTtl);
        $this->assertFalse($config->allowInsecureLogin);
        $this->assertFalse($config->trustProxy);
        $this->assertFalse($config->debug);
        $this->assertSame(12, $config->maxDepth);
        $this->assertSame(2000, $config->maxComplexity);
        $this->assertSame(1048576, $config->maxBodyBytes);
        $this->assertSame(31536000, $config->machineTtlMax);
        $this->assertTrue($config->voyager);
    }

    public function testMachineTtlMaxCanBeLowered(): void
    {
        $config = Config::fromArray(['secret' => self::SECRET, 'machine_ttl_max' => 86400]);

        $this->assertSame(86400, $config->machineTtlMax);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function badMachineTtlMax(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'a string' => ['31536000'],
            'a float' => [3.5],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider badMachineTtlMax
     * @param mixed $value
     */
    public function testMachineTtlMaxMustBeAPositiveInteger($value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('machine_ttl_max');
        Config::fromArray(['secret' => self::SECRET, 'machine_ttl_max' => $value]);
    }

    public function testOverrides(): void
    {
        $config = Config::fromArray([
            'secret' => self::SECRET,
            'access_ttl' => 60,
            'debug' => true,
            'fa_root' => '/fa',
        ]);

        $this->assertSame(60, $config->accessTtl);
        $this->assertTrue($config->debug);
        $this->assertSame('/fa', $config->faRoot);
    }

    public function testMissingSecretFailsClosed(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('secret');
        Config::fromArray([]);
    }

    public function testShortSecretFailsClosed(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('32');
        Config::fromArray(['secret' => 'too-short']);
    }

    public function testNonPositiveTtlFailsClosed(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray(['secret' => self::SECRET, 'access_ttl' => 0]);
    }

    /**
     * I-2: a string like 'false' or 'no' must not be coerced to boolean true, or
     * these fail-closed switches (plain-HTTP login, trusting X-Forwarded-*,
     * exception detail) silently fail open.
     *
     * @dataProvider booleanKeys
     */
    public function testNonBooleanValueForABooleanKeyFailsClosed(string $key): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($key);
        Config::fromArray(['secret' => self::SECRET, $key => 'false']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function booleanKeys(): array
    {
        return [
            'allow_insecure_login' => ['allow_insecure_login'],
            'trust_proxy' => ['trust_proxy'],
            'debug' => ['debug'],
            'voyager' => ['voyager'],
        ];
    }

    public function testUnknownKeyFailsClosed(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('acces_ttl');
        Config::fromArray(['secret' => self::SECRET, 'acces_ttl' => 60]);
    }

    public function testMissingFileFailsClosed(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('config_graphql.example.php');
        Config::fromFile('/nonexistent/config_graphql.php');
    }

    public function testFileMustReturnAnArray(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($file, '<?php return 1;');
        try {
            $this->expectException(ConfigException::class);
            Config::fromFile($file);
        } finally {
            unlink($file);
        }
    }
}
