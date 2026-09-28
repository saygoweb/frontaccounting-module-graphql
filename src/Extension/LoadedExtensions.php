<?php

namespace FA\GraphQL\Extension;

/**
 * The extensions that passed ExtensionLoader for this request, and what they add.
 */
final class LoadedExtensions
{
    /** @var array<int, array<string, mixed>> */
    private array $kept;

    /** @var callable(string): void */
    private $log;

    /**
     * @param array<int, array<string, mixed>> $kept name, query, mutation, targets, participants
     */
    public function __construct(array $kept, callable $log)
    {
        $this->kept = $kept;
        $this->log = $log;
    }

    public static function none(): self
    {
        return new self([], static function (string $line): void {
        });
    }

    /** @return string[] */
    public function names(): array
    {
        return array_column($this->kept, 'name');
    }

    /** @return array<string, array<string, mixed>> */
    public function queryFields(): array
    {
        return $this->merged('query');
    }

    /** @return array<string, array<string, mixed>> */
    public function mutationFields(): array
    {
        return $this->merged('mutation');
    }

    /** @return array<string, array<string, mixed>> */
    public function typeFields(string $type): array
    {
        return $this->forTarget($type);
    }

    /** @return array<string, array<string, mixed>> */
    public function inputFields(string $input): array
    {
        return $this->forTarget($input);
    }

    /** @return SalesOrderParticipant[] */
    public function salesOrderParticipants(): array
    {
        $participants = [];
        foreach ($this->kept as $extension) {
            foreach ($extension['participants'] as $participant) {
                if ($participant instanceof SalesOrderParticipant) {
                    $participants[] = $participant;
                }
            }
        }

        return $participants;
    }

    /** Which extension serves a root field ('query' or 'mutation'). */
    public function ownerOf(string $root, string $field): ?string
    {
        foreach ($this->kept as $extension) {
            if (isset($extension[$root][$field])) {
                return $extension['name'];
            }
        }

        return null;
    }

    /**
     * The core's list-shaped fields (as anorm-graphql's builders produce them) with
     * this target's contributions appended. A contribution whose name the core has is
     * dropped and logged: the core's field wins.
     *
     * @param array<int|string, array<string, mixed>> $coreFields
     * @return array<int|string, array<string, mixed>>
     */
    public function appendTo(string $target, array $coreFields): array
    {
        $coreNames = [];
        foreach ($coreFields as $key => $field) {
            $coreNames[is_int($key) ? (string) ($field['name'] ?? '') : $key] = true;
        }
        foreach ($this->kept as $extension) {
            foreach ($extension['targets'][$target] ?? [] as $field => $config) {
                if (isset($coreNames[$field])) {
                    ($this->log)(
                        "graphql extension {$extension['name']}: $target.$field clashes with the core; dropped"
                    );
                    continue;
                }
                $coreFields[] = $config;
            }
        }

        return $coreFields;
    }

    /** @return array<string, array<string, mixed>> */
    private function merged(string $root): array
    {
        $fields = [];
        foreach ($this->kept as $extension) {
            $fields += $extension[$root];
        }

        return $fields;
    }

    /** @return array<string, array<string, mixed>> */
    private function forTarget(string $target): array
    {
        $fields = [];
        foreach ($this->kept as $extension) {
            $fields += $extension['targets'][$target] ?? [];
        }

        return $fields;
    }
}
