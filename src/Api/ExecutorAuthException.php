<?php

namespace FA\GraphQL\Api;

/**
 * Executor refused a call before any GraphQL ran: what HTTP answers with a 401
 * (UNAUTHENTICATED: no, a bad, a revoked or an expired token; an inactive or
 * unknown user; a second company) or a 403 (FORBIDDEN: the user's role lacks
 * SA_GRAPHQL). Errors inside a document are in the envelope's `errors` instead.
 */
final class ExecutorAuthException extends \RuntimeException
{
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const FORBIDDEN = 'FORBIDDEN';

    private string $reason;

    public function __construct(string $reason, string $message, ?\Throwable $previous = null)
    {
        if ($reason !== self::UNAUTHENTICATED && $reason !== self::FORBIDDEN) {
            throw new \InvalidArgumentException("Unknown reason: $reason");
        }
        parent::__construct($message, 0, $previous);
        $this->reason = $reason;
    }

    /**
     * UNAUTHENTICATED or FORBIDDEN.
     */
    public function getReason(): string
    {
        return $this->reason;
    }
}
