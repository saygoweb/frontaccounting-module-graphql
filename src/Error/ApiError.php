<?php

namespace FA\GraphQL\Error;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

/**
 * An error whose message is safe to show an API client, with a stable code for
 * the client to branch on.
 */
abstract class ApiError extends \Exception implements ClientAware, ProvidesExtensions
{
    abstract public function code(): string;

    /**
     * The HTTP status when this error ends a request outside GraphQL (thrown by
     * middleware, not by a resolver). Inside GraphQL it is an entry in `errors`.
     */
    abstract public function httpStatus(): int;

    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtensions(): ?array
    {
        return ['code' => $this->code()];
    }
}
