<?php

namespace FA\GraphQL\Extension;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;

/**
 * The served schema: the generated ApiSchema, untouched — its literal fields arrays
 * stay editable by anorm-graphql — with the extensions' root fields added to new
 * Query and Mutation types (Release 4 spec §2.6). Every other type is the core's own
 * instance. With no extension root fields, the core schema is served as it is.
 */
final class SchemaAssembler
{
    public static function build(Schema $core, LoadedExtensions $extensions, ?callable $log = null): Schema
    {
        $query = $extensions->queryFields();
        $mutation = $extensions->mutationFields();
        if ($query === [] && $mutation === []) {
            return $core;
        }
        $log = $log ?? static function (string $line): void {
            error_log($line);
        };

        return new Schema([
            'query' => self::extend($core->getQueryType(), $query, 'query', $extensions, $log),
            'mutation' => self::extend($core->getMutationType(), $mutation, 'mutation', $extensions, $log),
        ]);
    }

    /**
     * @param array<string, array<string, mixed>> $extra
     */
    private static function extend(
        ?ObjectType $type,
        array $extra,
        string $root,
        LoadedExtensions $extensions,
        callable $log
    ): ?ObjectType {
        if ($type === null || $extra === []) {
            return $type;
        }
        $fields = $type->config['fields'];
        if (is_callable($fields)) {
            $fields = $fields();
        }
        $coreNames = array_flip($type->getFieldNames());
        foreach ($extra as $name => $config) {
            if (isset($coreNames[$name])) {
                $log('graphql extension ' . $extensions->ownerOf($root, $name)
                    . ": {$type->name}.$name clashes with the core; dropped");
                continue;
            }
            $fields[] = $config;
        }

        return new ObjectType([
            'name' => $type->name,
            'description' => $type->description,
            'fields' => $fields,
        ]);
    }
}
