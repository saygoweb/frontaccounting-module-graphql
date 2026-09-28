<?php

namespace FA\GraphQL\Tests\Unit\Extension;

use FA\GraphQL\Extension\AcceptsContributions;
use FA\GraphQL\Extension\ExtensibleType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class AcceptsContributionsTest extends TestCase
{
    /**
     * @param mixed $fields
     */
    private static function extensible($fields): ObjectType
    {
        return new class (['name' => 'Thing', 'fields' => $fields]) extends ObjectType implements ExtensibleType {
            use AcceptsContributions;

            public function __construct(array $config)
            {
                parent::__construct($config);
                $this->acceptContributions(null);
            }
        };
    }

    public function testCoreFieldsGivenAsAThunkAreStillTheCores(): void
    {
        $type = self::extensible(static function (): array {
            return ['id' => ['type' => Type::id()]];
        });

        $this->assertSame(['id'], array_keys($type->coreFields()));
        $this->assertSame(['id'], $type->getFieldNames());
    }

    public function testCoreFieldsOfAnotherShapeAreRefused(): void
    {
        $this->expectException(\LogicException::class);

        self::extensible(new \ArrayIterator(['id' => ['type' => Type::id()]]));
    }
}
