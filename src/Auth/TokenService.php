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
 *
 * Machine tokens (spec §3.7) are the same token with `typ: "machine"` and a long
 * life, capped by machine_ttl_max. Verifying one here checks only the signature and
 * claims; that its jti is still live is Authenticator's to ask (MachineTokenCheck).
 */
class TokenService
{
    private const LEEWAY = 'PT5S';

    public const TYPE_MACHINE = 'machine';

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

    /**
     * A machine token living $lifetime seconds. Only bin/fa-token issues these, by
     * way of MachineTokenService, which stores its jti: a machine token whose jti is
     * not stored is refused on use.
     *
     * @throws \InvalidArgumentException $lifetime is not positive or is longer than machine_ttl_max
     */
    public function issueMachine(int $company, string $login, int $lifetime): IssuedMachineToken
    {
        if ($lifetime < 1) {
            throw new \InvalidArgumentException('A machine token must live at least one second.');
        }
        if ($lifetime > $this->config->machineTtlMax) {
            throw new \InvalidArgumentException(sprintf(
                'A machine token may live at most %d seconds (machine_ttl_max); %d were asked for.',
                $this->config->machineTtlMax,
                $lifetime
            ));
        }
        // Whole seconds, so the stored row and the token's claims agree exactly.
        $now = $this->clock->now();
        $now = $now->setTimestamp($now->getTimestamp());
        $expires = $now->modify('+' . $lifetime . ' seconds');
        $jti = bin2hex(random_bytes(16));

        $token = $this->jwt->builder()
            ->issuedBy($this->config->issuer)
            ->identifiedBy($jti)
            ->relatedTo($login)
            ->withClaim('coy', $company)
            ->withClaim('typ', self::TYPE_MACHINE)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expires)
            ->getToken($this->jwt->signer(), $this->jwt->signingKey())
            ->toString();

        return new IssuedMachineToken($token, $jti, $now, $expires);
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

        // No typ: an access token. "machine": a machine token, which is only as
        // good as its jti, so it must have one. Anything else was not issued here.
        $machine = $claims->has('typ');
        if ($machine && $claims->get('typ') !== self::TYPE_MACHINE) {
            throw new InvalidToken('The access token is of an unknown type.');
        }
        $jti = $claims->get('jti', '');
        if ($machine && (!is_string($jti) || $jti === '')) {
            throw new InvalidToken('The access token is missing a required claim.');
        }

        return new Claims($company, $login, is_scalar($jti) ? (string) $jti : '', $expires, $machine);
    }
}
