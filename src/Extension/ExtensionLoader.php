<?php

namespace FA\GraphQL\Extension;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * The rules an extension's contributions must meet (Release 4 spec §2.5, revised).
 * Every problem drops the extension whole, participants included, with one log line:
 * another major contract version, an exception from any of its methods, a target the
 * core does not mark extensible, a field config webonyx cannot read, a non-null input
 * field, a bad field name, a root, type or input field the core (CoreSchema) or an
 * earlier extension already has, a type under the name of a different core or
 * earlier-extension type (the same type object is fine), a participant of no known
 * kind.
 *
 * Dropping whole matters while the core and an extension both write for one field:
 * an extension that lost its field but kept its participant would write beside the
 * core.
 */
final class ExtensionLoader
{
    public const EXTENSIBLE_TYPES = ['SalesOrderType'];
    public const EXTENSIBLE_INPUTS = ['SalesOrderCreateInput', 'SalesOrderUpdateInput'];

    /** Participant interfaces the core calls. */
    private const PARTICIPANT_KINDS = [SalesOrderParticipant::class];

    /** @var callable(string): void */
    private $log;

    public function __construct(?callable $log = null)
    {
        $this->log = $log ?? static function (string $line): void {
            error_log($line);
        };
    }

    /**
     * @param CoreSchema|null $core what the core serves; without it, only clashes
     *     between extensions are found
     */
    public function load(
        ExtensionRegistry $registry,
        ExtensionContext $context,
        ?CoreSchema $core = null
    ): LoadedExtensions {
        $core = $core ?? CoreSchema::none();
        $kept = [];
        $taken = ['name' => [], 'query' => [], 'mutation' => [], 'target' => [], 'types' => $core->types()];

        foreach ($registry->all() as $extension) {
            try {
                $name = $extension->name();
                $version = $extension->contractVersion();
                $contribution = [
                    'query' => $extension->queryFields($context),
                    'mutation' => $extension->mutationFields($context),
                    'types' => $extension->typeFields($context),
                    'inputs' => $extension->inputFields($context),
                    'participants' => $extension->participants($context),
                ];
            } catch (\Throwable $e) {
                $who = isset($name) ? $name : get_class($extension);
                $this->drop($who, get_class($e) . ': ' . $e->getMessage());
                unset($name);
                continue;
            }

            $newTypes = [];
            $problem = $this->problem($name, $version, $contribution, $taken, $core, $newTypes);
            if ($problem !== null) {
                $this->drop($name, $problem);
                unset($name);
                continue;
            }

            $taken['name'][$name] = true;
            $taken['types'] += $newTypes;
            foreach (['query', 'mutation'] as $root) {
                foreach (array_keys($contribution[$root]) as $field) {
                    $taken[$root][$field] = $name;
                }
            }
            $targets = $contribution['types'] + $contribution['inputs'];
            foreach ($targets as $target => $fields) {
                foreach (array_keys($fields) as $field) {
                    $taken['target'][$target][$field] = $name;
                }
            }
            $kept[] = [
                'name' => $name,
                'query' => self::named($contribution['query']),
                'mutation' => self::named($contribution['mutation']),
                'targets' => array_map([self::class, 'named'], $targets),
                'participants' => array_values($contribution['participants']),
            ];
            unset($name);
        }

        return new LoadedExtensions($kept, $this->log);
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, array<string, mixed>> $taken
     * @param array<string, Type> $newTypes set to the types this extension brings
     */
    private function problem(
        string $name,
        string $version,
        array $c,
        array $taken,
        CoreSchema $core,
        array &$newTypes
    ): ?string {
        if (isset($taken['name'][$name])) {
            return 'another extension already has this name';
        }
        if (self::major($version) !== self::major(ExtensionRegistry::CONTRACT_VERSION)) {
            return "built for contract version $version; this module speaks "
                . ExtensionRegistry::CONTRACT_VERSION;
        }
        foreach (['query', 'mutation', 'types', 'inputs', 'participants'] as $key) {
            if (!is_array($c[$key])) {
                return "$key must be an array";
            }
        }
        foreach (['query' => 'Query', 'mutation' => 'Mutation'] as $root => $rootType) {
            foreach ($c[$root] as $field => $config) {
                $bad = self::badField($field, $config);
                if ($bad !== null) {
                    return "$rootType.$field: $bad";
                }
                if ($core->has($rootType, $field)) {
                    return "$rootType.$field clashes with the core's";
                }
                if (isset($taken[$root][$field])) {
                    return "$rootType.$field is already served by extension " . $taken[$root][$field];
                }
            }
        }
        foreach (['types' => self::EXTENSIBLE_TYPES, 'inputs' => self::EXTENSIBLE_INPUTS] as $key => $allowed) {
            foreach ($c[$key] as $target => $fields) {
                if (!in_array($target, $allowed, true)) {
                    return "$target is not a " . ($key === 'types' ? 'type' : 'input')
                        . ' the core lets extensions add to';
                }
                if (!is_array($fields)) {
                    return "$target's fields must be an array";
                }
                foreach ($fields as $field => $config) {
                    $bad = self::badField($field, $config);
                    if ($bad !== null) {
                        return "$target.$field: $bad";
                    }
                    if ($core->has($target, $field)) {
                        return "$target.$field clashes with the core's";
                    }
                    if (isset($taken['target'][$target][$field])) {
                        return "$target.$field is already added by extension " . $taken['target'][$target][$field];
                    }
                }
            }
        }
        foreach ($c['participants'] as $participant) {
            if (!is_object($participant) || !self::knownParticipant($participant)) {
                return 'a participant implements no participant interface this module calls';
            }
        }

        return self::typeProblem($c, $taken['types'], $newTypes);
    }

    /**
     * Reads every contributed field as webonyx will, refuses a non-null input field,
     * and walks the types the fields reach: one bearing the name of a different core
     * or earlier-extension type would make the schema invalid.
     *
     * @param array<string, mixed> $c
     * @param array<string, Type> $known
     * @param array<string, Type> $newTypes
     */
    private static function typeProblem(array $c, array $known, array &$newTypes): ?string
    {
        $groups = ['Query' => [$c['query'], false], 'Mutation' => [$c['mutation'], false]];
        foreach ($c['types'] as $target => $fields) {
            $groups[$target] = [$fields, false];
        }
        foreach ($c['inputs'] as $target => $fields) {
            $groups[$target] = [$fields, true];
        }

        $reached = [];
        foreach ($groups as $target => [$fields, $isInput]) {
            if ($fields === []) {
                continue;
            }
            try {
                // A stand-in type, only to read the configs as webonyx reads them.
                $config = ['name' => 'ExtensionContribution', 'fields' => $fields];
                $read = $isInput ? new InputObjectType($config) : new ObjectType($config);
                foreach ($read->getFields() as $field) {
                    $type = $field->getType();
                    if ($isInput && $type instanceof NonNull) {
                        return "$target.{$field->name} must be nullable: "
                            . 'an extension cannot make a core input stricter';
                    }
                    $reached[] = $type;
                    if (!$isInput) {
                        foreach ($field->args as $arg) {
                            $reached[] = $arg->getType();
                        }
                    }
                }
            } catch (\Throwable $e) {
                return "$target: its fields cannot be read (" . get_class($e) . ': ' . $e->getMessage() . ')';
            }
        }

        try {
            $newTypes = CoreSchema::newTypes($reached, $known);
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            return 'its types cannot be read (' . get_class($e) . ': ' . $e->getMessage() . ')';
        }

        return null;
    }

    /**
     * @param mixed $field
     * @param mixed $config
     */
    private static function badField($field, $config): ?string
    {
        if (!is_string($field) || preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/', $field) !== 1) {
            return 'not a GraphQL field name';
        }
        if (!is_array($config) || !array_key_exists('type', $config)) {
            return 'a field config needs a type';
        }
        if (isset($config['name']) && $config['name'] !== $field) {
            return "its config names it '" . $config['name'] . "'";
        }

        return null;
    }

    private static function knownParticipant(object $participant): bool
    {
        foreach (self::PARTICIPANT_KINDS as $kind) {
            if ($participant instanceof $kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>> each config carrying its name
     */
    private static function named(array $fields): array
    {
        $named = [];
        foreach ($fields as $field => $config) {
            $named[$field] = ['name' => $field] + $config;
        }

        return $named;
    }

    private static function major(string $version): string
    {
        return explode('.', $version)[0];
    }

    private function drop(string $name, string $reason): void
    {
        ($this->log)("graphql extension $name: $reason; dropped");
    }
}
