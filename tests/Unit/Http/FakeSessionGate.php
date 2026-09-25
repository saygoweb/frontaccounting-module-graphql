<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\SessionGate;

/**
 * Records what the pipeline asked of the session gate, and fails on request.
 */
final class FakeSessionGate implements SessionGate
{
    public int $boots = 0;

    /** @var Claims[] */
    public array $entered = [];

    public ?\Throwable $throwOnBoot = null;
    public ?\Throwable $throwOnEnter = null;

    public function boot(): void
    {
        $this->boots++;
        if ($this->throwOnBoot !== null) {
            throw $this->throwOnBoot;
        }
    }

    public function enter(Claims $claims): void
    {
        if ($this->throwOnEnter !== null) {
            throw $this->throwOnEnter;
        }
        $this->entered[] = $claims;
    }
}
