<?php

namespace FA\GraphQL\Tests\Unit\Type;

use Anorm\GraphQL\ModelType;
use DI\Container;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\Type\FaModelType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class FaModelTypeTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['wa_current_user']);
    }

    private function signIn(array $areas): void
    {
        $_SESSION['wa_current_user'] = new class ($areas) {
            private array $areas;

            public function __construct(array $areas)
            {
                $this->areas = $areas;
            }

            // Mirrors FrontAccounting's current_user::logged_in()/can_access(),
            // which is what Guard calls; not this module's own naming.
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function logged_in(): bool
            {
                return true;
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function can_access(string $area): bool
            {
                return in_array($area, $this->areas, true);
            }
        };
    }

    /**
     * @param array<string, string> $areas
     */
    private function type(array $areas): FaModelType
    {
        return new class ($areas) extends FaModelType {
            private array $map;

            public function __construct(array $map)
            {
                $this->map = $map;
                parent::__construct(['name' => 'Probe', 'fields' => ['id' => Type::id()]]);
            }

            protected function modelClass(): string
            {
                return \stdClass::class;
            }

            protected function fields(): array
            {
                return [];
            }

            protected function areas(): array
            {
                return $this->map;
            }

            public function check(string $verb): void
            {
                $this->authorize($verb, null, new Container());
            }
        };
    }

    public function testAMappedVerbWithTheAreaHeldIsAllowed(): void
    {
        $this->signIn(['SA_SALESTYPES']);

        $this->type([ModelType::VERB_LIST => 'SA_SALESTYPES'])->check(ModelType::VERB_LIST);
        $this->addToAssertionCount(1);
    }

    public function testAMappedVerbWithoutTheAreaIsForbidden(): void
    {
        $this->signIn(['SA_GRAPHQL']);

        $this->expectException(Forbidden::class);
        $this->type([ModelType::VERB_LIST => 'SA_SALESTYPES'])->check(ModelType::VERB_LIST);
    }

    public function testAnUnmappedVerbIsForbiddenWhateverTheRoleHolds(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESTYPES', 'SA_SALESORDER']);

        $this->expectException(Forbidden::class);
        $this->type([ModelType::VERB_LIST => 'SA_SALESTYPES'])->check(ModelType::VERB_DELETE);
    }

    public function testNobodySignedInIsUnauthenticated(): void
    {
        $this->expectException(Unauthenticated::class);
        $this->type([ModelType::VERB_LIST => 'SA_SALESTYPES'])->check(ModelType::VERB_LIST);
    }

    public function testListIsRefusedBeforeAnythingTouchesTheDatabase(): void
    {
        // The container has no \PDO. Were authorize() not first, this would fail on
        // the missing connection instead of being Forbidden.
        $this->signIn(['SA_GRAPHQL']);

        $this->expectException(Forbidden::class);
        $this->type([ModelType::VERB_LIST => 'SA_SALESTYPES'])->resolveList(null, [], new Container());
    }

    public function testATypeCannotExistWithoutDeclaringItsAreas(): void
    {
        // PHP refuses to instantiate a generated <Entity>Type that did not add areas().
        $this->assertTrue((new \ReflectionMethod(FaModelType::class, 'areas'))->isAbstract());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function writeResolvers(): array
    {
        return [
            'create' => ['resolveCreate'],
            'update' => ['resolveUpdate'],
            'delete' => ['resolveDelete'],
            'upsert' => ['resolveUpsert'],
        ];
    }

    /**
     * Release 2 spec section 2.2: a generated Type whose write path was never
     * declared refuses, whatever the role holds, instead of writing the table.
     *
     * @dataProvider writeResolvers
     */
    public function testAWriteWithNoDeclaredPathIsForbidden(string $resolver): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESORDER', 'SA_CUSTOMER']);
        $type = $this->type([
            ModelType::VERB_LIST => 'SA_SALESORDER',
            ModelType::VERB_CREATE => 'SA_SALESORDER',
            ModelType::VERB_EDIT => 'SA_SALESORDER',
            ModelType::VERB_DELETE => 'SA_SALESORDER',
        ]);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('This entity is written through FrontAccounting; no write path is declared.');
        $type->$resolver(null, ['input' => [['id' => '1']], 'id' => ['1']], new Container());
    }
}
