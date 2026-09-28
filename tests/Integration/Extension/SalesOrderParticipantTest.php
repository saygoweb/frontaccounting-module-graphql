<?php

namespace FA\GraphQL\Tests\Integration\Extension;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Integration\SalesOrder\SalesOrderTestCase;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use FA\GraphQL\Tests\Support\Extension\FakeHooks;
use FA\GraphQL\Tests\Support\Extension\RecordingParticipant;
use FA\GraphQL\Tests\Support\FaTestRows;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SalesOrderParticipantTest extends SalesOrderTestCase
{
    private RecordingParticipant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        // Before anything asks Extensions (the service does, lazily, on its first write).
        $this->participant = new RecordingParticipant();
        $GLOBALS['Hooks']['fake_participant'] = new FakeHooks([
            new FakeExtension('fake_participant', ['participants' => [$this->participant]]),
        ]);
    }

    private static function orderCount(): int
    {
        return (int) FaTestRows::connect()->query('SELECT COUNT(*) FROM 0_sales_orders')->fetchColumn();
    }

    private function methods(): array
    {
        return array_column($this->participant->calls, 0);
    }

    public function testParticipantsSeeAWholeCreate(): void
    {
        $orderNo = $this->createOrder();

        $this->assertSame(['validate', 'afterCreate'], $this->methods());
        $this->assertSame($orderNo, $this->participant->calls[1][1]);
    }

    public function testAParticipantRefusingInValidateStopsTheOrderBeforeAnyWrite(): void
    {
        $this->participant->failIn = 'validate';
        $before = self::orderCount();
        $input = $this->orderInput();

        try {
            ServiceCall::run(function () use ($input): int {
                return $this->service()->create($input);
            });
            $this->fail('Expected the participant to refuse.');
        } catch (BadInput $e) {
            $this->assertSame('fake', $e->field());
        }
        $this->assertSame($before, self::orderCount());
    }

    public function testAParticipantFailingAfterCreateRollsTheOrderBack(): void
    {
        $this->participant->failIn = 'afterCreate';
        $before = self::orderCount();
        $input = $this->orderInput();

        try {
            ServiceCall::run(function () use ($input): int {
                return $this->service()->create($input);
            });
            $this->fail('Expected the participant failure to fail the mutation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('participant failed in afterCreate', $e->getMessage());
        }
        $this->assertSame($before, self::orderCount());
    }

    public function testParticipantsSeeUpdateDeleteAndClose(): void
    {
        $updated = $this->createOrder();
        $version = (int) $this->orderRow($updated)['version'];
        ServiceCall::run(function () use ($updated, $version): void {
            $this->service()->update(['id' => $updated, 'version' => $version, 'comments' => 'changed']);
        });
        $this->assertContains(['afterUpdate', $updated], $this->participant->calls);

        $deleted = $this->createOrder();
        ServiceCall::run(function () use ($deleted): string {
            return $this->service()->delete($deleted);
        });
        $this->assertContains(['afterDelete', $deleted], $this->participant->calls);

        $closed = $this->createOrder();
        $this->deliver($closed, [$this->lineIds($closed)[0] => 1]);
        ServiceCall::run(function () use ($closed): string {
            return $this->service()->delete($closed);
        });
        $this->assertContains(['afterClose', $closed], $this->participant->calls);
    }

    public function testARelaxingParticipantKeepsADeliveredOrdersHeaderEditable(): void
    {
        $orderNo = $this->createOrder();
        $this->deliver($orderNo, [$this->lineIds($orderNo)[0] => 1]);
        $this->participant->relaxed = true;
        $version = (int) $this->orderRow($orderNo)['version'];
        $nextDay = date('Y-m-d', strtotime($this->today() . ' +1 day'));

        ServiceCall::run(function () use ($orderNo, $version, $nextDay): void {
            $this->service()->update(['id' => $orderNo, 'version' => $version, 'orderDate' => $nextDay]);
        });

        $this->assertSame($nextDay, $this->orderRow($orderNo)['ord_date']);
    }

    /**
     * Binding ruling 1: an extension adding a field the core already has — here the
     * core's own `recurring` — is dropped whole, so its participant never writes
     * beside the core's code for that field. The well-behaved extension still runs.
     */
    public function testAnExtensionClashingWithACoreFieldTakesNoPartInASalesOrderCreate(): void
    {
        $clashing = new RecordingParticipant();
        $GLOBALS['Hooks']['fake_clashing'] = new FakeHooks([
            new FakeExtension('fake_clashing', [
                'types' => ['SalesOrderType' => ['recurring' => ['type' => Type::string()]]],
                'participants' => [$clashing],
            ]),
        ]);

        $input = $this->orderInput();
        $input['orderDate'] = $this->today();
        $result = GraphQL::executeQuery(
            $this->container->get(Schema::class),
            'mutation ($input: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $input) { id } }',
            null,
            $this->container,
            ['input' => [$input]]
        )->toArray(DebugFlag::INCLUDE_DEBUG_MESSAGE);
        if (isset($result['data']['salesOrderCreate'][0]['id'])) {
            $this->track((int) $result['data']['salesOrderCreate'][0]['id']);
        }

        $this->assertArrayNotHasKey('errors', $result, json_encode($result));
        $names = $this->container->get(Extensions::class)->loaded()->names();
        $this->assertNotContains('fake_clashing', $names);
        $this->assertContains('fake_participant', $names);
        $this->assertSame([], $clashing->calls);
        $this->assertSame(['validate', 'afterCreate'], $this->methods());
    }
}
