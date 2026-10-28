<?php

namespace FA\GraphQL\Tests\Integration\Banking;

use DI\Container;
use FA\GraphQL\ApiSchema;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\FaMessages;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Fa\Warnings;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;
use GraphQL\GraphQL;

/**
 * Bank entries and reconciliation against the demo company's bank account 1, signed
 * in as apitest. Entries are written straight to bank_trans with references
 * ZBRT<n> and trans_no from 990001, and removed in tearDown with their comments;
 * account 1's last reconciliation is put back as setUp found it.
 *
 * Every subclass must carry
 *
 *     @runTestsInSeparateProcesses
 *     @preserveGlobalState disabled
 */
abstract class BankingTestCase extends FaTestCase
{
    protected const ACCOUNT = 1;

    protected const REF = 'ZBRT';

    protected Container $container;

    /** @var array<string, mixed> bank_accounts row 1's last reconciliation, as found */
    private array $account = [];

    private int $next = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sweep();
        $row = $this->pdo()->query(
            'SELECT last_reconciled_date, ending_reconcile_balance FROM 0_bank_accounts WHERE id = ' . self::ACCOUNT
        )->fetch(\PDO::FETCH_ASSOC);
        $this->account = $row;
        $this->enter('apitest');
    }

    protected function tearDown(): void
    {
        $this->sweep();
        $this->pdo()->prepare(
            'UPDATE 0_bank_accounts SET last_reconciled_date = ?, ending_reconcile_balance = ? WHERE id = ?'
        )->execute([$this->account['last_reconciled_date'], $this->account['ending_reconcile_balance'], self::ACCOUNT]);
        parent::tearDown();
    }

    /** Signs in as $user (apitest: every area; apiorders: sales orders only). */
    protected function enter(string $user): void
    {
        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));
        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, $user, 'banking-test', new \DateTimeImmutable('+5 minutes')));
        FaIncludes::banking();
        FaMessages::reset();
        Warnings::reset();
    }

    /**
     * A bank_trans row of account 1; $columns override the defaults. Returns its id.
     *
     * @param array<string, mixed> $columns
     */
    protected function entry(array $columns = []): int
    {
        $this->next++;
        $row = array_merge([
            'type' => 1,
            'trans_no' => 990000 + $this->next,
            'bank_act' => self::ACCOUNT,
            'ref' => self::REF . $this->next,
            'trans_date' => '2031-01-15',
            'amount' => -10.0,
            'dimension_id' => 0,
            'dimension2_id' => 0,
            'person_type_id' => 0,
            'person_id' => 'Corner Shop',
            'reconciled' => null,
        ], $columns);
        $names = array_keys($row);
        $this->pdo()->prepare(
            'INSERT INTO 0_bank_trans (`' . implode('`, `', $names) . '`) VALUES ('
            . implode(', ', array_fill(0, count($names), '?')) . ')'
        )->execute(array_values($row));

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the whole result: data and errors
     */
    protected function gql(string $document, array $variables = []): array
    {
        return GraphQL::executeQuery(
            $this->container->get(ApiSchema::class),
            $document,
            null,
            $this->container,
            $variables
        )->toArray();
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> data, after asserting there are no errors
     */
    protected function data(string $document, array $variables = []): array
    {
        $result = $this->gql($document, $variables);
        $this->assertArrayNotHasKey('errors', $result, json_encode($result));

        return $result['data'];
    }

    private function sweep(): void
    {
        $this->pdo()->exec(
            "DELETE c FROM 0_comments c JOIN 0_bank_trans b ON c.type = b.type AND c.id = b.trans_no WHERE b.ref LIKE '"
            . self::REF . "%'"
        );
        $this->pdo()->exec("DELETE FROM 0_bank_trans WHERE ref LIKE '" . self::REF . "%'");
    }
}
