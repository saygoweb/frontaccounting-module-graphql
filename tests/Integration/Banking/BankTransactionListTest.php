<?php

namespace FA\GraphQL\Tests\Integration\Banking;

/**
 * bankTransactionList and bankTransactionCount (Bank reconciliation spec §3.1).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BankTransactionListTest extends BankingTestCase
{
    private const FIELDS = 'id transType transNo bankAccountId reference date amount reconciled'
        . ' personType personId voided memo counterparty';

    /**
     * @param array<string, mixed> $selector
     * @return array<int, array<string, mixed>>
     */
    private function list(array $selector, string $fields = self::FIELDS): array
    {
        return $this->data(
            'query ($q: MangoInput) { bankTransactionList(query: $q) { ' . $fields . ' } }',
            ['q' => ['selector' => json_encode($selector), 'sort' => ['id']]]
        )['bankTransactionList'];
    }

    public function testFieldsMapToBankTrans(): void
    {
        $id = $this->entry(['amount' => -42.42, 'trans_date' => '2031-01-07', 'reconciled' => '2031-01-31']);
        $this->pdo()->prepare('INSERT INTO 0_comments (type, id, date_, memo_) VALUES (1, ?, ?, ?)')
            ->execute([990001, '2031-01-07', 'TPP Wholesale']);

        $rows = $this->list(['id' => (string) $id]);

        $this->assertSame([[
            'id' => (string) $id,
            'transType' => 1,
            'transNo' => 990001,
            'bankAccountId' => (string) self::ACCOUNT,
            'reference' => self::REF . '1',
            'date' => '2031-01-07',
            'amount' => -42.42,
            'reconciled' => '2031-01-31',
            'personType' => 0,
            'personId' => 'Corner Shop',
            'voided' => false,
            'memo' => 'TPP Wholesale',
            'counterparty' => 'Corner Shop',
        ]], $rows);
    }

    public function testUnreconciledVoidedAndStatementSelectors(): void
    {
        $open = $this->entry(['trans_date' => '2031-01-10']);
        $later = $this->entry(['trans_date' => '2031-02-10']);
        $voided = $this->entry(['amount' => 0]);
        $ticked = $this->entry(['reconciled' => '2031-01-31']);
        $mine = ['reference' => ['$beginswith' => self::REF]];

        $toTick = $this->list(
            $mine + ['reconciled' => null, 'voided' => false, 'date' => ['$lte' => '2031-01-31']],
            'id'
        );
        $this->assertSame([['id' => (string) $open]], $toTick);

        $statement = $this->list($mine + ['reconciled' => '2031-01-31'], 'id');
        $this->assertSame([['id' => (string) $ticked]], $statement);

        $this->assertSame([['id' => (string) $voided]], $this->list($mine + ['voided' => true], 'id'));
        $this->assertCount(4, $this->list($mine, 'id'));
        $this->assertCount(4, $this->list($mine + ['transType' => 1], 'id'));
        $this->assertCount(0, $this->list($mine + ['transType' => 12], 'id'));
        $this->assertSame((string) $later, $this->list($mine + ['date' => ['$gt' => '2031-01-31']], 'id')[0]['id']);
    }

    public function testCountAgreesWithList(): void
    {
        $this->entry();
        $this->entry(['reconciled' => '2031-01-31']);
        $selector = json_encode(['reference' => ['$beginswith' => self::REF], 'reconciled' => null]);

        $count = $this->data(
            'query ($q: MangoInput) { bankTransactionCount(query: $q) }',
            ['q' => ['selector' => $selector]]
        )['bankTransactionCount'];

        $this->assertSame(1, $count);
    }

    public function testCounterpartyIsFrontAccountingsPayeeName(): void
    {
        $customer = $this->entry(['type' => 12, 'person_type_id' => 2, 'person_id' => '1']);
        $supplier = $this->entry(['type' => 22, 'person_type_id' => 3, 'person_id' => '1']);
        $quick = $this->entry(['person_type_id' => 4, 'person_id' => '1']);

        foreach ([$customer => [2, '1'], $supplier => [3, '1'], $quick => [4, '1']] as $id => [$type, $person]) {
            $row = $this->list(['id' => (string) $id], 'counterparty')[0];
            $this->assertSame(\payment_person_name($type, $person, false), $row['counterparty'], "entry $id");
            $this->assertNotSame('', $row['counterparty']);
        }
    }

    public function testListingNeedsBankTransView(): void
    {
        $this->enter('apiorders');

        $result = $this->gql('{ bankTransactionList { id } }');

        $this->assertSame('FORBIDDEN', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
    }
}
