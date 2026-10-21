<?php

namespace FA\GraphQL\Tests\Integration\Payment;

use FA\GraphQL\ApiSchema;
use FA\GraphQL\Fa\Service\Voider;
use GraphQL\GraphQL;

/**
 * Issue #27: invoiceList's total, outstanding and voided, deliveryList's voided and
 * customerPaymentList's voided and unallocated are filterable and sortable, and the
 * value a selector compares is the value the field resolves to.
 *
 * The fixtures are debtor_trans rows of a customer of their own, written straight to
 * the table so that each edge FrontAccounting reaches only over many documents is
 * there at once: an open invoice, a part-paid one, a paid one, one left open by
 * rounding either way, one settled by a credit note, a voided one, a prepayment
 * invoice, one with freight and a discount; deliveries and payments likewise. The
 * reference values are FrontAccounting's own definitions, worked out here in PHP
 * from the columns (custalloc_db.inc's Total, FLOAT_COMP_DELTA, get_voided_entry()).
 * One more test runs FrontAccounting's own invoice, payment and void.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ComputedFieldQueryTest extends PaymentTestCase
{
    /** FrontAccounting's FLOAT_COMP_DELTA (includes/current_user.inc). */
    private const DELTA = 0.004;

    private ?int $customer = null;

    /** @var array<int, array{0: int, 1: int}> [type, trans_no] of each voided row written */
    private array $voidedRows = [];

    /** @var array<string, int> fixture name => trans_no, per type */
    private array $invoices = [];

    /** @var array<string, int> */
    private array $deliveries = [];

    /** @var array<string, int> */
    private array $payments = [];

    protected function tearDown(): void
    {
        if ($this->customer !== null) {
            $this->pdo()->prepare('DELETE FROM 0_cust_allocations WHERE person_id = ?')->execute([$this->customer]);
            foreach ($this->voidedRows as [$type, $no]) {
                $this->pdo()->prepare('DELETE FROM 0_voided WHERE type = ? AND id = ?')->execute([$type, $no]);
            }
            $this->pdo()->prepare('DELETE FROM 0_debtor_trans WHERE debtor_no = ?')->execute([$this->customer]);
            $this->removeCustomer($this->customer);
        }
        parent::tearDown();
    }

    public function testEachComputedValueIsTheValueItsSelectorCompares(): void
    {
        $this->fixtures();
        foreach (
            ['invoice' => ['total', 'outstanding', 'voided'], 'delivery' => ['voided'],
            'customerPayment' => ['voided', 'unallocated']] as $entity => $fields
        ) {
            $rows = $this->list($entity, ['customerId' => (string) $this->customer], [], $fields);
            $this->assertCount(count($this->fixtureIds($entity)), $rows, $entity);
            foreach ($rows as $row) {
                $reference = $this->reference($entity, (int) $row['id']);
                foreach ($fields as $field) {
                    $this->assertSame($reference[$field], $row[$field], "$entity {$row['id']} $field");
                    // The row's own value, compared in SQL, finds the row.
                    $value = $row[$field];
                    $condition = is_bool($value) ? $value : ['$gte' => $value - 0.001, '$lte' => $value + 0.001];
                    $found = $this->list($entity, ['id' => $row['id'], $field => $condition], [], ['id']);
                    $message = "$entity {$row['id']} $field = " . json_encode($value);
                    $this->assertSame([['id' => $row['id']]], $found, $message);
                }
            }
        }
    }

    public function testOutstandingGreaterThanZeroIsExactlyTheOpenInvoices(): void
    {
        $this->fixtures();
        $selector = ['customerId' => (string) $this->customer, 'outstanding' => ['$gt' => 0]];
        $open = $this->list('invoice', $selector, ['-date', '-id'], ['id', 'outstanding']);

        $expected = ['open', 'partPaid', 'prepaid', 'freight', 'roundedUp'];
        $this->assertEqualsCanonicalizing($this->ids('invoice', $expected), array_column($open, 'id'));
        // Newest first, as asked.
        $newestFirst = $this->ids('invoice', ['roundedUp', 'freight', 'prepaid', 'partPaid', 'open']);
        $this->assertSame($newestFirst, array_column($open, 'id'));
        foreach ($open as $row) {
            $this->assertGreaterThan(0, $row['outstanding']);
        }
        $this->assertSame(count($expected), $this->rowCount('invoice', $selector));

        // Settled, rounding residue included; voided too (its amounts are zeroed).
        $settled = ['customerId' => (string) $this->customer, 'outstanding' => 0];
        $this->assertEqualsCanonicalizing(
            $this->ids('invoice', ['paid', 'roundedDown', 'roundedNegative', 'credited', 'voided']),
            array_column($this->list('invoice', $settled, [], ['id']), 'id')
        );
        $this->assertSame(5, $this->rowCount('invoice', $settled));
    }

    public function testVoidedFalseLeavesOutVoidedDocuments(): void
    {
        $this->fixtures();
        foreach (['invoice', 'delivery', 'customerPayment'] as $entity) {
            $all = $this->fixtureIds($entity);
            $voided = [(string) $this->fixtureIdsByName($entity)['voided']];
            foreach ([false => array_values(array_diff($all, $voided)), true => $voided] as $flag => $expected) {
                $selector = ['customerId' => (string) $this->customer, 'voided' => (bool) $flag];
                $this->assertEqualsCanonicalizing(
                    $expected,
                    array_column($this->list($entity, $selector, [], ['id']), 'id'),
                    "$entity voided: " . json_encode((bool) $flag)
                );
                $this->assertSame(count($expected), $this->rowCount($entity, $selector), "$entity count");
            }
        }
    }

    public function testComputedFieldsSort(): void
    {
        $this->fixtures();
        $byCustomer = ['customerId' => (string) $this->customer];
        $cases = [['invoice', 'outstanding'], ['invoice', 'total'], ['customerPayment', 'unallocated']];
        foreach ($cases as [$entity, $field]) {
            $rows = $this->list($entity, $byCustomer, ['-' . $field, 'id'], ['id', $field]);
            $sorted = $rows;
            usort($sorted, static function (array $a, array $b) use ($field): int {
                return [$b[$field], (int) $a['id']] <=> [$a[$field], (int) $b['id']];
            });
            $this->assertSame($sorted, $rows, "$entity by -$field");
        }
        $payments = $this->list('customerPayment', $byCustomer + ['unallocated' => ['$gt' => 0]], [], ['id']);
        $this->assertSame($this->ids('customerPayment', ['partAllocated']), array_column($payments, 'id'));
    }

    /**
     * FrontAccounting's own documents: an invoice, part paid; a second invoice, voided
     * by FrontAccounting's void; a payment, voided. The selector and the fields agree.
     */
    public function testFrontAccountingsOwnDocumentsAgree(): void
    {
        $partPaid = $this->invoice();
        $total = $this->invoiceTotal($partPaid);
        $this->pay(['amount' => 5.0, 'allocations' => [['invoiceId' => $partPaid, 'amount' => 5.0]]]);
        $voided = $this->invoice();
        $this->locked(function () use ($voided): void {
            $this->container->get(Voider::class)->void(10, $voided, 'issue #27');
        });
        $payment = $this->pay(['amount' => 3.0]);
        $this->locked(function () use ($payment): void {
            $this->container->get(Voider::class)->void(12, $payment, 'issue #27');
        });

        $ids = ['$in' => [(string) $partPaid, (string) $voided]];
        $rows = $this->list('invoice', ['id' => $ids], ['id'], ['id', 'total', 'outstanding', 'voided']);
        $this->assertSame([
            ['id' => (string) $partPaid, 'total' => $total, 'outstanding' => round($total - 5.0, 2), 'voided' => false],
            ['id' => (string) $voided, 'total' => 0.0, 'outstanding' => 0.0, 'voided' => true],
        ], $rows);
        $this->assertSame(
            [(string) $partPaid],
            array_column(
                $this->list('invoice', ['id' => $ids, 'outstanding' => ['$gt' => 0], 'voided' => false], [], ['id']),
                'id'
            )
        );
        $this->assertSame(1, $this->rowCount('invoice', ['id' => $ids, 'outstanding' => ['$gt' => 0]]));
        $this->assertSame(
            [['id' => (string) $payment, 'voided' => true, 'unallocated' => 0.0]],
            $this->list(
                'customerPayment',
                ['id' => (string) $payment, 'voided' => true],
                [],
                ['id', 'voided', 'unallocated']
            )
        );
    }

    /**
     * The customer's documents. Dates rise with the fixture's order, so -date lists
     * the later ones first.
     */
    private function fixtures(): void
    {
        $this->customer = $this->branchlessCustomer();
        $invoices = [
            // name => [ov_amount, ov_gst, ov_freight, ov_freight_tax, ov_discount, alloc, prep_amount]
            'open' => [100, 15, 0, 0, 0, 0, 0],
            'partPaid' => [200, 30, 0, 0, 0, 50, 0],
            'paid' => [50, 7.5, 0, 0, 0, 57.5, 0],
            'roundedDown' => [10, 1.5, 0, 0, 0, 11.497, 0],
            'roundedNegative' => [10, 1.5, 0, 0, 0, 11.502, 0],
            'credited' => [80, 12, 0, 0, 0, 92, 0],
            'voided' => [0, 0, 0, 0, 0, 0, 0],
            'prepaid' => [100, 15, 0, 0, 0, 10, 40],
            'freight' => [60, 9, 10, 1.5, 2, 0, 0],
            'roundedUp' => [10, 1.51, 0, 0, 0, 11.5, 0],
        ];
        $day = 0;
        foreach ($invoices as $name => $amounts) {
            $this->invoices[$name] = $this->trans(10, $amounts, ++$day);
        }
        $this->voided(10, $this->invoices['voided']);
        // The credit note that settles 'credited', and its allocation.
        $credit = $this->trans(11, [80, 12, 0, 0, 0, 92, 0], ++$day);
        $this->allocate(11, $credit, $this->invoices['credited'], 92);

        foreach (['delivered' => [100, 15, 0, 0, 0, 0, 0], 'voided' => [0, 0, 0, 0, 0, 0, 0]] as $name => $amounts) {
            $this->deliveries[$name] = $this->trans(13, $amounts, ++$day);
        }
        $this->voided(13, $this->deliveries['voided']);

        $payments = [
            'partAllocated' => [100, 0, 0, 0, 0, 60, 0],
            'allocated' => [57.5, 0, 0, 0, 0, 57.5, 0],
            'withDiscount' => [48, 0, 0, 0, 2, 50, 0],
            'residue' => [11.497, 0, 0, 0, 0, 11.5, 0],
            'voided' => [0, 0, 0, 0, 0, 0, 0],
        ];
        foreach ($payments as $name => $amounts) {
            $this->payments[$name] = $this->trans(12, $amounts, ++$day);
        }
        $this->voided(12, $this->payments['voided']);
        $this->allocate(12, $this->payments['partAllocated'], $this->invoices['partPaid'], 50);
    }

    /**
     * @param array<int, float|int> $amounts ov_amount, ov_gst, ov_freight, ov_freight_tax, ov_discount, alloc,
     *                                       prep_amount
     */
    private function trans(int $type, array $amounts, int $day): int
    {
        $statement = $this->pdo()->prepare('SELECT COALESCE(MAX(trans_no), 0) + 1 FROM 0_debtor_trans WHERE type = ?');
        $statement->execute([$type]);
        $no = (int) $statement->fetchColumn();
        $this->pdo()->prepare(
            'INSERT INTO 0_debtor_trans (trans_no, type, debtor_no, branch_code, tran_date, due_date, reference,
                ov_amount, ov_gst, ov_freight, ov_freight_tax, ov_discount, alloc, prep_amount)
             VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute(array_merge(
            [$no, $type, $this->customer, "2020-01-$day", "2020-02-$day", "GQL27-$type-$no"],
            $amounts
        ));

        return $no;
    }

    private function voided(int $type, int $no): void
    {
        $this->pdo()->prepare("INSERT INTO 0_voided (type, id, date_, memo_) VALUES (?, ?, '2020-03-01', 'issue #27')")
            ->execute([$type, $no]);
        $this->voidedRows[] = [$type, $no];
    }

    private function allocate(int $fromType, int $from, int $to, float $amount): void
    {
        $this->pdo()->prepare(
            "INSERT INTO 0_cust_allocations (person_id, amt, date_alloc, trans_no_from, trans_type_from,
                trans_no_to, trans_type_to) VALUES (?, ?, '2020-03-01', ?, ?, ?, 10)"
        )->execute([$this->customer, $amount, $from, $fromType, $to]);
    }

    /**
     * FrontAccounting's definitions, worked out in PHP from the row's columns.
     *
     * @return array<string, mixed>
     */
    private function reference(string $entity, int $id): array
    {
        $type = ['invoice' => 10, 'delivery' => 13, 'customerPayment' => 12][$entity];
        $row = array_map('floatval', array_filter($this->transRow($type, $id), 'is_numeric'));
        $settle = static function (float $raw): float {
            return abs($raw) <= self::DELTA ? 0.0 : round($raw, 2);
        };
        $voided = (bool) $this->container->get(Voider::class)->isVoided($type, $id);
        if ($entity === 'invoice') {
            // custalloc_db.inc: IF(prep_amount, prep_amount, ov_amount+ov_gst+ov_freight+ov_freight_tax+ov_discount)
            $total = $row['prep_amount'] > 0 ? round($row['prep_amount'], 2) : round(
                $row['ov_amount'] + $row['ov_gst'] + $row['ov_freight'] + $row['ov_freight_tax'] + $row['ov_discount'],
                2
            );

            return ['total' => $total, 'outstanding' => $settle($total - $row['alloc']), 'voided' => $voided];
        }
        if ($entity === 'customerPayment') {
            $unallocated = $settle($row['ov_amount'] + $row['ov_discount'] - $row['alloc']);

            return ['voided' => $voided, 'unallocated' => $unallocated];
        }

        return ['voided' => $voided];
    }

    /**
     * @return array<string, int>
     */
    private function fixtureIdsByName(string $entity): array
    {
        $byEntity = [
            'invoice' => $this->invoices,
            'delivery' => $this->deliveries,
            'customerPayment' => $this->payments,
        ];

        return $byEntity[$entity];
    }

    /**
     * @return string[]
     */
    private function fixtureIds(string $entity): array
    {
        return array_map('strval', array_values($this->fixtureIdsByName($entity)));
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    private function ids(string $entity, array $names): array
    {
        $byName = $this->fixtureIdsByName($entity);

        return array_map(static function (string $name) use ($byName): string {
            return (string) $byName[$name];
        }, $names);
    }

    /**
     * @param array<string, mixed> $selector
     * @param string[] $sort
     * @param string[] $fields
     * @return array<int, array<string, mixed>>
     */
    private function list(string $entity, array $selector, array $sort, array $fields): array
    {
        if (!in_array('id', $fields, true)) {
            array_unshift($fields, 'id');
        }
        $query = ['selector' => json_encode($selector)];
        if ($sort !== []) {
            $query['sort'] = $sort;
        }

        $document = "query (\$q: MangoInput) { {$entity}List(query: \$q) { " . implode(' ', $fields) . ' } }';

        return $this->gql($document, $query)
            [$entity . 'List'];
    }

    /**
     * @param array<string, mixed> $selector
     */
    private function rowCount(string $entity, array $selector): int
    {
        $document = "query (\$q: MangoInput) { {$entity}Count(query: \$q) }";

        return $this->gql($document, ['selector' => json_encode($selector)])
            [$entity . 'Count'];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function gql(string $document, array $query): array
    {
        $result = GraphQL::executeQuery(
            $this->container->get(ApiSchema::class),
            $document,
            null,
            $this->container,
            ['q' => $query]
        )->toArray();
        $this->assertArrayNotHasKey('errors', $result, json_encode($result));

        return $result['data'];
    }
}
