<?php

namespace FA\GraphQL\Auth;

use FA\GraphQL\Config;
use FA\GraphQL\Error\InvalidToken;
use Lcobucci\Clock\Clock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;

/**
 * Access tokens: HS256, never stored. The company travels in the token so that a
 * request can never choose its own.
 */
class TokenService
{
    private const LEEWAY = 'PT5S';

    private Config $config;
    private Clock $clock;
    private Configuration $jwt;

    public function __construct(Config $config, Clock $clock)
    {
        $this->config = $config;
        $this->clock = $clock;
        $this->jwt = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($config->secret));
    }

    public function accessTtl(): int
    {
        return $this->config->accessTtl;
    }

    public function issueAccess(int $company, string $login): string
    {
        $now = $this->clock->now();

        return $this->jwt->builder()
            ->issuedBy($this->config->issuer)
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->relatedTo($login)
            ->withClaim('coy', $company)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+' . $this->config->accessTtl . ' seconds'))
            ->getToken($this->jwt->signer(), $this->jwt->signingKey())
            ->toString();
    }

    public function verify(string $jwt): Claims
    {
        try {
            $token = $this->jwt->parser()->parse($jwt);
        } catch (\Throwable $e) {
            throw new InvalidToken('The access token is malformed.', 0, $e);
        }
        if (!$token instanceof UnencryptedToken) {
            throw new InvalidToken('The access token is malformed.');
        }

        // SignedWith compares the header's algorithm with the signer's as well as
        // the signature, which is what refuses alg=none and HS512-with-our-key.
        $valid = $this->jwt->validator()->validate(
            $token,
            new SignedWith($this->jwt->signer(), $this->jwt->signingKey()),
            new IssuedBy($this->config->issuer),
            new LooseValidAt($this->clock, new \DateInterval(self::LEEWAY))
        );
        if (!$valid) {
            throw new InvalidToken('The access token is invalid or has expired.');
        }

        $claims = $token->claims();
        $company = $claims->get('coy');
        $login = $claims->get('sub');
        $expires = $claims->get('exp');
        if (!is_int($company) || !is_string($login) || $login === '' || !$expires instanceof \DateTimeImmutable) {
            throw new InvalidToken('The access token is missing a required claim.');
        }

        return new Claims($company, $login, (string) $claims->get('jti', ''), $expires);
    }
}
