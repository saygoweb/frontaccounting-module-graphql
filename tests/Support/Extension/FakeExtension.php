<?php

namespace FA\GraphQL\Tests\Support\Extension;

use FA\GraphQL\Extension\Extension;
use FA\GraphQL\Extension\ExtensionContext;

/**
 * An extension a test configures field by field. `$throwIn` names one method that
 * throws instead of answering.
 */
final class FakeExtension implements Extension
{
    public string $name;
    public string $version = '1.0';
    /** @var array<string, mixed> */
    public array $query = [];
    /** @var array<string, mixed> */
    public array $mutation = [];
    /** @var array<string, array<string, mixed>> */
    public array $types = [];
    /** @var array<string, array<string, mixed>> */
    public array $inputs = [];
    /** @var array<int, object> */
    public array $participants = [];
    public ?string $throwIn = null;

    /**
     * @param array<string, mixed> $options property => value
     */
    public function __construct(string $name, array $options = [])
    {
        $this->name = $name;
        foreach ($options as $property => $value) {
            $this->$property = $value;
        }
    }

    public function name(): string
    {
        $this->maybeThrow('name');

        return $this->name;
    }

    public function contractVersion(): string
    {
        $this->maybeThrow('contractVersion');

        return $this->version;
    }

    public function queryFields(ExtensionContext $c): array
    {
        $this->maybeThrow('queryFields');

        return $this->query;
    }

    public function mutationFields(ExtensionContext $c): array
    {
        $this->maybeThrow('mutationFields');

        return $this->mutation;
    }

    public function typeFields(ExtensionContext $c): array
    {
        $this->maybeThrow('typeFields');

        return $this->types;
    }

    public function inputFields(ExtensionContext $c): array
    {
        $this->maybeThrow('inputFields');

        return $this->inputs;
    }

    public function participants(ExtensionContext $c): array
    {
        $this->maybeThrow('participants');

        return $this->participants;
    }

    private function maybeThrow(string $method): void
    {
        if ($this->throwIn === $method) {
            throw new \RuntimeException("boom in $method");
        }
    }
}
