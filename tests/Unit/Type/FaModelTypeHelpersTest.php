<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Model\SalesTypeModel;
use FA\GraphQL\Type\FaModelType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class FaModelTypeHelpersTest extends TestCase
{
    private function type(): object
    {
        return new class extends FaModelType {
            public function __construct()
            {
                parent::__construct(['name' => 'StubType', 'fields' => ['id' => ['type' => Type::id()]]]);
            }

            protected function modelClass(): string
            {
                return SalesTypeModel::class;
            }

            protected function fields(): array
            {
                return [];
            }

            protected function areas(): array
            {
                return [];
            }

            // Named callIntId/callIntIds, not id()/ids(): FaModelType's ancestor
            // GraphQL\Type\Definition\Type declares a static id(): ScalarType, and
            // PHP 7.4 enforces return-type compatibility even for statics — id($id): int
            // here would be a fatal "declaration must be compatible" error.
            public static function callIntId($id): int
            {
                return self::intId($id);
            }

            public static function callIntIds(array $ids): array
            {
                return self::intIds($ids);
            }
        };
    }

    /**
     * @dataProvider validIds
     */
    public function testAPositiveIntegerIdIsAccepted($given, int $expected): void
    {
        $type = $this->type();
        $this->assertSame($expected, $type::callIntId($given));
    }

    public function validIds(): array
    {
        return ['int' => [5, 5], 'string' => ['42', 42]];
    }

    /**
     * @dataProvider invalidIds
     */
    public function testAnythingElseIsBadInput($given): void
    {
        $type = $this->type();
        $this->expectException(BadInput::class);
        $type::callIntId($given);
    }

    public function invalidIds(): array
    {
        return [
            'zero' => ['0'], 'negative' => ['-1'], 'trailing text' => ['5 anything'],
            'float' => ['1.5'], 'empty' => [''], 'null' => [null], 'array' => [[1]],
            'leading zero' => ['05'], 'too long' => ['12345678901'],
        ];
    }

    public function testABadIdInAListIsNamedByItsIndex(): void
    {
        $type = $this->type();
        $this->assertSame([3, 4], $type::callIntIds(['3', 4]));
        try {
            $type::callIntIds(['3', '4 OR 1=1', '5']);
            $this->fail('a bad id was accepted');
        } catch (BadInput $e) {
            $this->assertSame('id', $e->field());
            $this->assertSame(1, $e->index());
        }
    }
}
