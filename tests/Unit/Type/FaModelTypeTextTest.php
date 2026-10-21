<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Type\FaModelType;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Every string a FaModelType serves is FrontAccounting's stored text decoded
 * (saygoweb/frontaccounting-module-graphql#16): the row's own columns and what a
 * field's resolver reads.
 */
class FaModelTypeTextTest extends TestCase
{
    private function probe(): FaModelType
    {
        return new class extends FaModelType {
            public function __construct()
            {
                parent::__construct(['name' => 'Probe', 'fields' => $this->fields()]);
            }

            protected function modelClass(): string
            {
                return \stdClass::class;
            }

            protected function fields(): array
            {
                return [
                    ['name' => 'id', 'type' => Type::id()],
                    ['name' => 'description', 'type' => Type::string()],
                    ['name' => 'stockId', 'type' => Type::nonNull(Type::id())],
                    ['name' => 'tags', 'type' => Type::listOf(Type::string())],
                    ['name' => 'quantity', 'type' => Type::float()],
                    [
                        'name' => 'memo',
                        'type' => Type::string(),
                        'resolve' => function (array $row): ?string {
                            return $row['memo'] === null ? null : $row['memo'] . ' &amp; more';
                        },
                    ],
                ];
            }

            protected function areas(): array
            {
                return [];
            }
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function query(array $rows, string $selection): array
    {
        $probe = $this->probe();
        $schema = new Schema(['query' => new ObjectType([
            'name' => 'Query',
            'fields' => [
                'probes' => [
                    'type' => Type::listOf($probe),
                    'resolve' => function () use ($rows): array {
                        return $rows;
                    },
                ],
            ],
        ])]);
        $result = GraphQL::executeQuery($schema, "{ probes $selection }")->toArray();
        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result));

        return $result['data']['probes'];
    }

    public function testStringsAreDecodedAndOtherValuesLeftAlone(): void
    {
        $rows = [[
            'id' => '5',
            'description' => 'Hosting - Clubs &amp; Charities &lt;b&gt;&quot;Gold&quot;&lt;/b&gt; &#039;24',
            'stockId' => 'A&amp;B',
            'tags' => ['R&amp;D', 'plain', null],
            'quantity' => 2.5,
            'memo' => 'Paid &quot;cash&quot;',
        ]];

        $this->assertSame([[
            'id' => '5',
            'description' => 'Hosting - Clubs & Charities <b>"Gold"</b> \'24',
            'stockId' => 'A&B',
            'tags' => ['R&D', 'plain', null],
            'quantity' => 2.5,
            'memo' => 'Paid "cash" & more',
        ]], $this->query($rows, '{ id description stockId tags quantity memo }'));
    }

    public function testNullsAndAbsentColumnsStayNull(): void
    {
        $rows = [['id' => '6', 'description' => null, 'stockId' => 'X', 'memo' => null]];

        $this->assertSame(
            [['id' => '6', 'description' => null, 'tags' => null, 'memo' => null]],
            $this->query($rows, '{ id description tags memo }')
        );
    }
}
