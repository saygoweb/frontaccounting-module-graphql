<?php

namespace FA\GraphQL;

/**
 * config_graphql.php, validated. There is no default secret: a missing or weak one
 * stops the endpoint rather than minting tokens anyone could forge.
 */
final class Config
{
    private const DEFAULTS = [
        'secret' => '',
        'issuer' => 'fa-graphql',
        'access_ttl' => 900,
        'refresh_ttl' => 2592000,
        'allow_insecure_login' => false,
        'trust_proxy' => false,
        'debug' => false,
        'max_depth' => 12,
        'max_complexity' => 2000,
        'max_body_bytes' => 1048576,
        'fa_root' => '',
        'machine_ttl_max' => 31536000,
    ];

    public string $secret;
    public string $issuer;
    public int $accessTtl;
    public int $refreshTtl;
    public bool $allowInsecureLogin;
    public bool $trustProxy;
    public bool $debug;
    public int $maxDepth;
    public int $maxComplexity;
    public int $maxBodyBytes;
    public string $faRoot;
    /** The longest a machine token may live, in seconds (spec §3.7). */
    public int $machineTtlMax;

    private function __construct()
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigException(
                basename($path) . ' is missing. Copy config_graphql.example.php to it and set a secret.'
            );
        }
        $values = require $path;
        if (!is_array($values)) {
            throw new ConfigException(basename($path) . ' must return an array.');
        }

        return self::fromArray($values);
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $unknown = array_diff(array_keys($values), array_keys(self::DEFAULTS));
        if ($unknown) {
            throw new ConfigException('Unknown configuration key: ' . implode(', ', $unknown));
        }
        $v = $values + self::DEFAULTS;

        if (!is_string($v['secret']) || $v['secret'] === '') {
            throw new ConfigException('The configuration has no secret.');
        }
        if (strlen($v['secret']) < 32) {
            throw new ConfigException('The configured secret must be at least 32 bytes.');
        }
        $ints = ['access_ttl', 'refresh_ttl', 'max_depth', 'max_complexity', 'max_body_bytes', 'machine_ttl_max'];
        foreach ($ints as $key) {
            if (!is_int($v[$key]) || $v[$key] < 1) {
                throw new ConfigException("Configuration key $key must be a positive integer.");
            }
        }
        foreach (['allow_insecure_login', 'trust_proxy', 'debug'] as $key) {
            if (!is_bool($v[$key])) {
                throw new ConfigException("Configuration key $key must be a boolean.");
            }
        }

        $config = new self();
        $config->secret = $v['secret'];
        $config->issuer = (string) $v['issuer'];
        $config->accessTtl = $v['access_ttl'];
        $config->refreshTtl = $v['refresh_ttl'];
        $config->allowInsecureLogin = $v['allow_insecure_login'];
        $config->trustProxy = $v['trust_proxy'];
        $config->debug = $v['debug'];
        $config->maxDepth = $v['max_depth'];
        $config->maxComplexity = $v['max_complexity'];
        $config->maxBodyBytes = $v['max_body_bytes'];
        $config->faRoot = (string) $v['fa_root'];
        $config->machineTtlMax = $v['machine_ttl_max'];

        return $config;
    }
}
