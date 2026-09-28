<?php

namespace FA\GraphQL\Tests\Support\Extension;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Extension\SalesOrderParticipant;

/**
 * Records every call. `$failIn` names one method that throws; `$relaxed` is what
 * isRelaxed() answers.
 */
final class RecordingParticipant implements SalesOrderParticipant
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];
    public ?string $failIn = null;
    public bool $relaxed = false;

    public function validate(array $input): void
    {
        $this->calls[] = ['validate'];
        if ($this->failIn === 'validate') {
            throw new BadInput('The fake participant refused this order.', 'fake');
        }
    }

    public function isRelaxed(int $orderId, array $input): bool
    {
        $this->calls[] = ['isRelaxed', $orderId];

        return $this->relaxed;
    }

    public function afterCreate(int $orderId, array $input): void
    {
        $this->record('afterCreate', $orderId);
    }

    public function afterUpdate(int $orderId, array $input): void
    {
        $this->record('afterUpdate', $orderId);
    }

    public function afterDelete(int $orderId): void
    {
        $this->record('afterDelete', $orderId);
    }

    public function afterClose(int $orderId): void
    {
        $this->record('afterClose', $orderId);
    }

    private function record(string $method, int $orderId): void
    {
        $this->calls[] = [$method, $orderId];
        if ($this->failIn === $method) {
            throw new \RuntimeException("participant failed in $method");
        }
    }
}
