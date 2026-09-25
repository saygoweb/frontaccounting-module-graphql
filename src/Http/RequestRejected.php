<?php

namespace FA\GraphQL\Http;

/**
 * The request cannot be executed as sent: the body is too large, not JSON, has no
 * query, or is a batch. Carries the HTTP status JsonErrorMiddleware answers with.
 */
class RequestRejected extends \RuntimeException
{
    private int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }
}
