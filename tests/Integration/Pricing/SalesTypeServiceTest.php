<?php

namespace FA\GraphQL\Tests\Integration\Pricing;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\Service\SalesTypeService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;

/**
 * SalesTypeService against FrontAccounting in-process, as apitest (and, for the
 * refusal, as apiorders). Every list written carries this test's prefix and is swept
 * in tearDown.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesTypeServiceTest extends FaTestCase
{
    private Container $container;

    private SessionGate $gate;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));
        $this->gate = $this->container->get(SessionGate::class);
        $this->gate->boot();
        $this->enterAs('apitest');
        $this->prefix = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        FaTestRows::sweepItems($this->pdo(), $this->prefix);
        parent::tearDown();
    }

    private function enterAs(string $user): void
    {
        $this->gate->enter(new Claims(0, $user, 'sales-type-test', new \DateTimeImmutable('+5 minutes')));
    }

    private function create(array $input): int
    {
        $service = new SalesTypeService();

        return ServiceCall::each([$input], function (array $item) use ($service): int {
            return $service->create($item);
        })[0];
    }

    private function update(array $input): void
    {
        $service = new SalesTypeService();
        ServiceCall::each([$input], function (array $item) use ($service): int {
            $service->update($item);

            return (int) $item['id'];
        });
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM 0_sales_types WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetch(\PDO::FETCH_ASSOC);
    }

    private function assertBadInput(string $field, string $message, callable $call): void
    {
        try {
            $call();
            $this->fail("expected BadInput on $field");
        } catch (BadInput $e) {
            $this->assertSame($field, $e->field(), $e->getMessage());
            $this->assertSame($message, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function gql(string $query, array $variables = []): array
    {
        $result = GraphQL::executeQuery(
            $this->container->get(Schema::class),
            $query,
            null,
            $this->container,
            $variables
        )->toArray();

        return $result;
    }

    public function testANewListHasThePagesDefaults(): void
    {
        $id = $this->create(['name' => "{$this->prefix} Reseller"]);

        $row = $this->row($id);
        $this->assertSame("{$this->prefix} Reseller", $row['sales_type']);
        $this->assertEquals(1, $row['factor']);
        $this->assertSame('0', (string) $row['tax_included']);
        $this->assertSame('0', (string) $row['inactive']);
    }

    public function testEveryGivenValueIsStored(): void
    {
        $id = $this->create([
            'name' => "{$this->prefix} R", 'factor' => 0.85, 'taxIncluded' => true, 'inactive' => true,
        ]);

        $row = $this->row($id);
        $this->assertEquals(0.85, $row['factor']);
        $this->assertSame('1', (string) $row['tax_included']);
        $this->assertSame('1', (string) $row['inactive']);
    }

    public function testAnEmptyNameIsRefused(): void
    {
        $this->assertBadInput('name', 'The sales type description cannot be empty.', function () {
            $this->create(['name' => '']);
        });
    }

    public function testANegativeFactorIsRefused(): void
    {
        $this->assertBadInput('factor', 'Calculation factor must be valid positive number.', function () {
            $this->create(['name' => "{$this->prefix} N", 'factor' => -1]);
        });
    }

    public function testAnExistingNameIsRefused(): void
    {
        $this->assertBadInput('name', "A price list named 'Retail' already exists.", function () {
            $this->create(['name' => 'Retail']);
        });
    }

    public function testAnUpdateKeepsWhatItDoesNotName(): void
    {
        $id = $this->create(['name' => "{$this->prefix} U", 'taxIncluded' => true, 'factor' => 0.9]);

        $this->update(['id' => $id, 'factor' => 0.5]);

        $row = $this->row($id);
        $this->assertSame("{$this->prefix} U", $row['sales_type']);
        $this->assertSame('1', (string) $row['tax_included']);
        $this->assertEquals(0.5, $row['factor']);
    }

    public function testAnUpdateReactivatesAList(): void
    {
        $id = $this->create(['name' => "{$this->prefix} I", 'inactive' => true]);

        $this->update(['id' => $id, 'inactive' => false]);

        $this->assertSame('0', (string) $this->row($id)['inactive']);
    }

    public function testAnUnknownIdIsNotFound(): void
    {
        try {
            $this->update(['id' => 999999, 'factor' => 1]);
            $this->fail('expected NotFound');
        } catch (NotFound $e) {
            $this->assertSame("Sales type id '999999' not found", $e->getMessage());
        }
    }

    public function testAListMayBeRenamedToItsOwnName(): void
    {
        $id = $this->create(['name' => "{$this->prefix} Same"]);

        $this->update(['id' => $id, 'name' => "{$this->prefix} Same", 'factor' => 0.4]);

        $this->assertEquals(0.4, $this->row($id)['factor']);
    }

    public function testAnotherListsNameIsRefusedOnUpdate(): void
    {
        $id = $this->create(['name' => "{$this->prefix} Other"]);

        $this->assertBadInput('name', "A price list named 'Wholesale' already exists.", function () use ($id) {
            $this->update(['id' => $id, 'name' => 'Wholesale']);
        });
    }

    public function testANullNameIsRefused(): void
    {
        $this->assertBadInput('name', 'name cannot be null.', function () {
            $this->create(['name' => null]);
        });
    }

    public function testOnlyTheBaseListIsBase(): void
    {
        $id = $this->create(['name' => "{$this->prefix} NotBase"]);

        $result = $this->gql('{ salesTypeList { id isBase } }');

        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result));
        $byId = array_column($result['data']['salesTypeList'], 'isBase', 'id');
        $this->assertTrue($byId['1']);
        $this->assertFalse($byId[(string) $id]);
        $this->assertCount(1, array_filter($byId));
    }

    public function testTheBaseListStaysBaseWhenItIsDeactivated(): void
    {
        $this->update(['id' => 1, 'inactive' => true]);
        try {
            $result = $this->gql('{ salesTypeList { id isBase } }');
            $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result));
            $byId = array_column($result['data']['salesTypeList'], 'isBase', 'id');
            $this->assertTrue($byId['1']);
        } finally {
            $this->update(['id' => 1, 'inactive' => false]);
        }
    }

    public function testAUserWithoutSalesTypesAccessIsForbidden(): void
    {
        $this->enterAs('apiorders');
        $this->assertFalse($_SESSION['wa_current_user']->can_access('SA_SALESTYPES'));

        $result = $this->gql(
            'mutation ($input: [SalesTypeCreateInput!]!) { salesTypeCreate(input: $input) { id } }',
            ['input' => [['name' => "{$this->prefix} Denied"]]]
        );

        $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'], (string) json_encode($result));
        $this->assertSame(
            0,
            (int) $this->pdo()->query("SELECT COUNT(*) FROM 0_sales_types WHERE sales_type LIKE '{$this->prefix}%'")
                ->fetchColumn()
        );
    }
}
