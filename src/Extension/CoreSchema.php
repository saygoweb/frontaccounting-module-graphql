<?php

namespace FA\GraphQL\Extension;

use FA\GraphQL\ApiSchema;
use FA\GraphQL\Type\SalesOrder\SalesOrderCreateInput;
use FA\GraphQL\Type\SalesOrder\SalesOrderType;
use FA\GraphQL\Type\SalesOrder\SalesOrderUpdateInput;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use Psr\Container\ContainerInterface;

/**
 * What the core serves, as ExtensionLoader needs it before any extension is accepted
 * (Release 4 spec §2.5, revised): the core's root field names, the extensible types'
 * own field names, and the core's named types by name. An extension that would add a
 * field the core has, or bring a different type under a core type's name, is then
 * dropped whole — participants included.
 *
 * Read from the request's own ApiSchema and extensible types (container singletons),
 * never a second build of them. The extensible types add their extension fields
 * lazily (AcceptsContributions), so reading their core fields here does not ask
 * Extensions, which is what is loading.
 */
final class CoreSchema
{
    /** The core classes of ExtensionLoader::EXTENSIBLE_TYPES and EXTENSIBLE_INPUTS. */
    public const EXTENSIBLE = [SalesOrderType::class, SalesOrderCreateInput::class, SalesOrderUpdateInput::class];

    /** @var array<string, array<string, true>> type name => field name => true */
    private array $fields;

    /** @var array<string, Type> name => the core's instance */
    private array $types;

    /**
     * @param array<string, string[]> $fields type name ('Query', 'Mutation', an extensible type) => field names
     * @param array<string, Type> $types name => the core's instance
     */
    public function __construct(array $fields = [], array $types = [])
    {
        $this->fields = [];
        foreach ($fields as $type => $names) {
            $this->fields[$type] = array_fill_keys($names, true);
        }
        $this->types = $types;
    }

    /** Knows no core: only clashes between extensions are found. */
    public static function none(): self
    {
        return new self();
    }

    public static function fromContainer(ContainerInterface $container): self
    {
        $extensible = [];
        foreach (self::EXTENSIBLE as $class) {
            $extensible[] = $container->get($class);
        }

        return self::of($container->get(ApiSchema::class), $extensible);
    }

    /**
     * @param Type[] $extensible the core's ExtensibleType instances
     */
    public static function of(Schema $schema, array $extensible): self
    {
        $fields = [];
        $roots = [];
        foreach ([$schema->getQueryType(), $schema->getMutationType()] as $root) {
            if ($root !== null) {
                $fields[$root->name] = $root->getFieldNames();
                $roots[] = $root;
            }
        }
        foreach ($extensible as $type) {
            if ($type instanceof NamedType && $type instanceof ExtensibleType) {
                $fields[$type->name] = array_map('strval', array_keys(self::fieldsOf($type)));
            }
        }

        return new self($fields, self::newTypes($roots, []));
    }

    /** Whether the core's $type ('Query', 'Mutation', 'SalesOrderType', ...) has $field. */
    public function has(string $type, string $field): bool
    {
        return isset($this->fields[$type][$field]);
    }

    /**
     * @return array<string, Type> name => the core's instance
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * The named types reachable from $roots — through fields, arguments, interfaces
     * and union members — that $known does not hold. A type $known holds is not
     * entered: it is known already.
     *
     * @param Type[] $roots
     * @param array<string, Type> $known name => instance
     * @return array<string, Type> name => instance
     * @throws \UnexpectedValueException when a type's name is another instance's
     */
    public static function newTypes(array $roots, array $known): array
    {
        $found = [];
        $queue = $roots;
        while ($queue !== []) {
            $type = Type::getNamedType(array_pop($queue));
            if (!$type instanceof Type) {
                continue;
            }
            $name = $type->name;
            $seen = $known[$name] ?? $found[$name] ?? null;
            if ($seen !== null) {
                if ($seen !== $type) {
                    throw new \UnexpectedValueException(
                        "type $name is a different type from the $name "
                        . (isset($known[$name]) ? 'already in the schema' : 'it uses elsewhere')
                    );
                }
                continue;
            }
            $found[$name] = $type;
            foreach (self::children($type) as $child) {
                $queue[] = $child;
            }
        }

        return $found;
    }

    /**
     * @return Type[]
     */
    private static function children(Type $type): array
    {
        $children = [];
        if ($type instanceof UnionType) {
            return $type->getTypes();
        }
        if ($type instanceof ObjectType || $type instanceof InterfaceType) {
            foreach ($type->getInterfaces() as $interface) {
                $children[] = $interface;
            }
        }
        foreach (self::fieldsOf($type) as $field) {
            $children[] = $field->getType();
            if ($field instanceof FieldDefinition) {
                foreach ($field->args as $arg) {
                    $children[] = $arg->getType();
                }
            }
        }

        return $children;
    }

    /**
     * A type's fields as webonyx reads them; an ExtensibleType's own fields only.
     *
     * @return array<string, FieldDefinition|InputObjectField>
     */
    private static function fieldsOf(Type $type): array
    {
        if ($type instanceof ExtensibleType && $type instanceof NamedType) {
            $config = ['name' => $type->name, 'fields' => $type->coreFields()];
            $type = $type instanceof InputObjectType ? new InputObjectType($config) : new ObjectType($config);
        }
        if ($type instanceof ObjectType || $type instanceof InterfaceType || $type instanceof InputObjectType) {
            return $type->getFields();
        }

        return [];
    }
}
