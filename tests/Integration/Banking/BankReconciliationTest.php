<?php

namespace FA\GraphQL\Tests\Integration\Banking;

/**
 * bankReconciliation and bankReconcile (Bank reconciliation spec §3.2, §3.3), checked
 * against FrontAccounting's own get_max_reconciled() and the Reconcile Bank Account
 * screen's SQL. Statement dates are in 2031, after any demo data.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BankReconciliationTest extends BankingTestCase
{
    private const SUMMARY = 'bankAccountId statementDate beginningBalance reconciledAmount endingBalance accountTotal '
        . 'lastReconciledDate storedEndingBalance difference statementDates';

    private const JAN = '2031-01-31';

    private const FEB = '2031-02-28';

    /** @return array<string, mixed> */
    private function summary(string $date, int $account = self::ACCOUNT): array
    {
        return $this->data(
            'query ($a: ID!, $d: Date!) { bankReconciliation(bankAccountId: $a, statementDate: $d) { '
            . self::SUMMARY . ' } }',
            ['a' => (string) $account, 'd' => $date]
        )['bankReconciliation'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> the whole result
     */
    private function reconcile(array $input): array
    {
        return $this->gql(
            'mutation ($i: BankReconcileInput!) { bankReconcile(input: $i) { ' . self::SUMMARY . ' } }',
            ['i' => $input + ['bankAccountId' => (string) self::ACCOUNT]]
        );
    }

    /** @param array<string, mixed> $input */
    private function reconciled(array $input): array
    {
        $result = $this->reconcile($input);
        $this->assertArrayNotHasKey('errors', $result, json_encode($result));

        return $result['data']['bankReconcile'];
    }

    private function reconciledDate(int $id): ?string
    {
        $value = $this->pdo()->query('SELECT reconciled FROM 0_bank_trans WHERE id = ' . $id)->fetchColumn();

        return $value === null ? null : (string) $value;
    }

    /** @return array<string, float> FrontAccounting's own sums for $date */
    private function faSums(string $date): array
    {
        $row = \db_fetch(\get_max_reconciled(\sql2date($date), self::ACCOUNT));

        return [
            'beginning' => round((float) $row['beg_balance'], 2),
            'ending' => round((float) $row['end_balance'], 2),
            'total' => round((float) $row['total'], 2),
        ];
    }

    public function testSummaryIsFrontAccountingsArithmetic(): void
    {
        $this->entry(['amount' => 100.0, 'reconciled' => self::JAN]);
        $this->entry(['amount' => -30.0, 'trans_date' => '2031-02-03', 'reconciled' => self::FEB]);
        $this->entry(['amount' => -5.0]);

        $summary = $this->summary(self::FEB);
        $fa = $this->faSums(self::FEB);

        $this->assertSame($fa['beginning'], $summary['beginningBalance']);
        $this->assertSame($fa['ending'], $summary['endingBalance']);
        $this->assertSame(round($fa['ending'] - $fa['beginning'], 2), $summary['reconciledAmount']);
        $this->assertSame(-30.0, $summary['reconciledAmount']);
        $this->assertSame($fa['total'], $summary['accountTotal']);
        $this->assertSame(self::FEB, $summary['statementDates'][0]);
        $this->assertSame(self::JAN, $summary['statementDates'][1]);
    }

    public function testTickingRecordsTheStatementAndTheDifferenceGoesToZero(): void
    {
        $before = $this->summary(self::JAN)['endingBalance'];
        $a = $this->entry(['amount' => -42.42]);
        $b = $this->entry(['amount' => 100.0]);
        $closing = round($before + 57.58, 2);

        $summary = $this->reconciled([
            'statementDate' => self::JAN, 'endingBalance' => $closing, 'reconcile' => [(string) $a, (string) $b],
        ]);

        $this->assertSame(self::JAN, $this->reconciledDate($a));
        $this->assertSame(self::JAN, $this->reconciledDate($b));
        $this->assertSame(57.58, $summary['reconciledAmount']);
        $this->assertSame(self::JAN, $summary['lastReconciledDate']);
        $this->assertSame($closing, $summary['storedEndingBalance']);
        $this->assertSame(0.0, $summary['difference']);
    }

    public function testTheScreenShowsWhatTheApiTicked(): void
    {
        $a = $this->entry();
        $this->reconciled(['statementDate' => self::JAN, 'endingBalance' => 0, 'reconcile' => [(string) $a]]);

        $sql = \get_sql_for_bank_account_reconcile(self::ACCOUNT, \sql2date(self::JAN));
        $rows = [];
        $result = \db_query($sql);
        while ($row = \db_fetch($result)) {
            $rows[(int) $row['id']] = $row['reconciled'];
        }

        $this->assertSame(self::JAN, $rows[$a]);
    }

    public function testUntickingClearsTheEntry(): void
    {
        $a = $this->entry(['reconciled' => self::JAN]);

        $this->reconciled(['statementDate' => self::JAN, 'endingBalance' => 0, 'unreconcile' => [(string) $a]]);

        $this->assertNull($this->reconciledDate($a));
    }

    public function testRepeatingACallChangesNothing(): void
    {
        $a = $this->entry();
        $b = $this->entry(['reconciled' => null]);
        $input = ['statementDate' => self::JAN, 'endingBalance' => 0, 'reconcile' => [(string) $a],
            'unreconcile' => [(string) $b]];

        $first = $this->reconciled($input);
        $second = $this->reconciled($input);

        $this->assertSame($first, $second);
        $this->assertSame(self::JAN, $this->reconciledDate($a));
        $this->assertNull($this->reconciledDate($b));
    }

    public function testWithNoIdsItRecordsTheBalanceOnly(): void
    {
        $summary = $this->reconciled(['statementDate' => self::JAN, 'endingBalance' => 1234.5]);

        $this->assertSame(self::JAN, $summary['lastReconciledDate']);
        $this->assertSame(1234.5, $summary['storedEndingBalance']);
    }

    public function testADuplicateIdIsTickedOnce(): void
    {
        $a = $this->entry(['amount' => -7.0]);

        $summary = $this->reconciled([
            'statementDate' => self::JAN, 'endingBalance' => 0, 'reconcile' => [(string) $a, (string) $a],
        ]);

        $this->assertSame(self::JAN, $this->reconciledDate($a));
        $this->assertSame(-7.0, $summary['reconciledAmount']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public function refusals(): array
    {
        return [
            'another account' => [['bank_act' => 2], 'is not an entry of bank account 1'],
            'voided' => [['amount' => 0], 'is voided'],
            'dated after the statement' => [['trans_date' => '2031-02-02'], 'after the statement'],
            'on another statement' => [['reconciled' => '2030-12-31'], 'unreconcile it there first'],
        ];
    }

    /**
     * @dataProvider refusals
     * @param array<string, mixed> $columns
     */
    public function testRefusalsWriteNothing(array $columns, string $reason): void
    {
        $good = $this->entry();
        $bad = $this->entry($columns);
        $before = $this->reconciledDate($bad);

        $result = $this->reconcile([
            'statementDate' => self::JAN, 'endingBalance' => 0, 'reconcile' => [(string) $good, (string) $bad],
        ]);

        $this->assertSame('BAD_INPUT', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
        $this->assertStringContainsString((string) $bad, $result['errors'][0]['message']);
        $this->assertStringContainsString($reason, $result['errors'][0]['message']);
        $this->assertNull($this->reconciledDate($good));
        $this->assertSame($before, $this->reconciledDate($bad));
    }

    public function testUntickingAnEntryOfAnotherStatementIsRefused(): void
    {
        $a = $this->entry(['reconciled' => '2030-12-31']);

        $result = $this->reconcile([
            'statementDate' => self::JAN, 'endingBalance' => 0, 'unreconcile' => [(string) $a],
        ]);

        $this->assertSame('BAD_INPUT', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
        $this->assertSame('2030-12-31', $this->reconciledDate($a));
    }

    public function testAnIdInBothListsIsRefused(): void
    {
        $a = $this->entry();

        $result = $this->reconcile([
            'statementDate' => self::JAN, 'endingBalance' => 0, 'reconcile' => [(string) $a],
            'unreconcile' => [(string) $a],
        ]);

        $this->assertSame('BAD_INPUT', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
        $this->assertStringContainsString('both', $result['errors'][0]['message']);
    }

    public function testANonNumericIdIsBadInput(): void
    {
        foreach (['abc', '0'] as $id) {
            $result = $this->reconcile(['statementDate' => self::JAN, 'endingBalance' => 0, 'reconcile' => [$id]]);
            $this->assertSame('BAD_INPUT', $result['errors'][0]['extensions']['code'] ?? null, $id);
        }
    }

    public function testFloatResidueReadsAsZero(): void
    {
        $a = $this->entry(['amount' => 0.1]);
        $b = $this->entry(['amount' => 0.2]);
        $c = $this->entry(['amount' => -0.3]);
        $before = $this->summary(self::FEB);

        $summary = $this->reconciled([
            'statementDate' => self::FEB, 'endingBalance' => $before['endingBalance'],
            'reconcile' => [(string) $a, (string) $b, (string) $c],
        ]);

        // assertSame(0.0, -0.0) passes in PHP; the string form tells them apart ('-0').
        $this->assertSame('0', (string) $summary['reconciledAmount']);
        $this->assertSame('0', (string) $summary['difference']);
    }

    public function testUnknownAccountIsNotFound(): void
    {
        $result = $this->gql(
            'query { bankReconciliation(bankAccountId: "99999", statementDate: "2031-01-31") { endingBalance } }'
        );

        $this->assertSame('NOT_FOUND', $result['errors'][0]['extensions']['code'] ?? null, json_encode($result));
    }

    public function testReconcilingNeedsSaReconcile(): void
    {
        $this->enter('apiorders');

        $read = $this->gql(
            'query { bankReconciliation(bankAccountId: "1", statementDate: "2031-01-31") { endingBalance } }'
        );
        $write = $this->reconcile(['statementDate' => self::JAN, 'endingBalance' => 0]);

        $this->assertSame('FORBIDDEN', $read['errors'][0]['extensions']['code'] ?? null, json_encode($read));
        $this->assertSame('FORBIDDEN', $write['errors'][0]['extensions']['code'] ?? null, json_encode($write));
    }
}
