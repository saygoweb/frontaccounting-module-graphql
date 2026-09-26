<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\ApiError;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\DateConversion;
use FA\GraphQL\Fa\FaErrorException;
use FA\GraphQL\Fa\FaMessages;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\FaTransaction;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Warnings;

/**
 * The write plumbing against a real FrontAccounting: its transaction functions, its
 * message functions and its date format. Probe rows go into this module's own
 * graphql_refresh_token table (the one table the module owns) and are counted on a
 * second connection, which sees only what was committed.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WritePlumbingTest extends FaTestCase
{
    private const CLIENT = 'write-plumbing-test';

    private function session(): FaSession
    {
        return new FaSession(Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef']));
    }

    private function enter(): FaSession
    {
        $session = $this->session();
        $session->enter(new Claims(0, 'apitest', 'jti', new \DateTimeImmutable('+5 minutes')));
        FaMessages::reset();
        Warnings::reset();

        return $session;
    }

    protected function tearDown(): void
    {
        if (CompanyContext::isSet()) {
            $this->pdo()->prepare('DELETE FROM ' . CompanyContext::prefix() . 'graphql_refresh_token WHERE client = ?')
                ->execute([self::CLIENT]);
        }
        Warnings::reset();
    }

    private function insert(string $hash): void
    {
        db_query(
            'INSERT INTO ' . TB_PREF . 'graphql_refresh_token (user_id, token_hash, issued_at, expires_at, client)'
            . ' VALUES (1, ' . db_escape($hash) . ', NOW(), NOW(), ' . db_escape(self::CLIENT) . ')',
            'could not insert the probe row'
        );
    }

    private function committed(string $hash): int
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . CompanyContext::prefix() . 'graphql_refresh_token WHERE token_hash = ?'
        );
        $statement->execute([$hash]);

        return (int) $statement->fetchColumn();
    }

    private function hash(string $label): string
    {
        return hash('sha256', $label . uniqid('', true));
    }

    public function testRunCommitsTheWork(): void
    {
        $this->enter();
        $hash = $this->hash('commit');

        $this->assertSame(7, FaTransaction::run(function () use ($hash) {
            $this->insert($hash);
            return 7;
        }));
        $this->assertSame(1, $this->committed($hash));
        $this->assertSame(0, $GLOBALS['transaction_level']);
    }

    public function testAnyThrowableRollsBackEvenInsideANestedLevelAndResetsTheCounter(): void
    {
        $this->enter();
        $hash = $this->hash('rollback');

        try {
            FaTransaction::run(function () use ($hash) {
                $this->insert($hash);
                // As Cart::write() and add_crm_person() do inside our transaction.
                begin_transaction();
                throw new \RuntimeException('boom');
            });
            $this->fail('expected the exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(0, $this->committed($hash));
        $this->assertSame(0, $GLOBALS['transaction_level']);
    }

    public function testAFailedQueryRollsBackAndTheNextWriteInTheSameRequestStillCommits(): void
    {
        $this->enter();
        $first = $this->hash('first');
        $second = $this->hash('second');

        try {
            FaTransaction::run(function () use ($first) {
                $this->insert($first);
                db_query('SELECT * FROM no_such_table_write_plumbing', 'the probe query failed');
            });
            $this->fail('expected FaErrorException');
        } catch (FaErrorException $e) {
            $this->addToAssertionCount(1);
        }
        FaTransaction::run(function () use ($second) {
            $this->insert($second);
        });

        $this->assertSame(0, $this->committed($first));
        $this->assertSame(1, $this->committed($second));
    }

    public function testAnErrorMessageBecomesFaRejectedAndNothingIsKept(): void
    {
        $this->enter();
        $hash = $this->hash('error');

        try {
            ServiceCall::run(function () use ($hash) {
                $this->insert($hash);
                display_error('Credit limit exceeded');
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertSame('Credit limit exceeded', $e->getMessage());
            $this->assertSame(['Credit limit exceeded'], $e->getExtensions()['messages']);
        }
        $this->assertSame(0, $this->committed($hash));
    }

    public function testWarningsAreKeptAndNoticesDropped(): void
    {
        $this->enter();
        $hash = $this->hash('warning');

        $result = ServiceCall::run(function () use ($hash) {
            $this->insert($hash);
            display_warning('Price below cost');
            display_notification('The order has been saved.');
            return 42;
        });

        $this->assertSame(42, $result);
        $this->assertSame(1, $this->committed($hash));
        $this->assertSame(['Price below cost'], Warnings::all());
    }

    public function testWarningsOfRolledBackWorkAreNotReported(): void
    {
        $this->enter();

        try {
            ServiceCall::run(function () {
                display_warning('Price below cost');
                display_error('Customer not found');
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], Warnings::all());
    }

    public function testStaleMessagesFromBeforeTheCallAreIgnored(): void
    {
        $this->enter();
        display_error('left over from an earlier call');

        $this->assertSame(1, ServiceCall::run(function () {
            return 1;
        }));
    }

    public function testADuplicateKeyIsFaRejectedWithFrontAccountingsMessage(): void
    {
        $this->enter();
        $hash = $this->hash('duplicate');

        try {
            ServiceCall::run(function () use ($hash) {
                $this->insert($hash);
                $this->insert($hash);
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('duplicate', $e->getMessage());
        }
        $this->assertSame(0, $this->committed($hash));
    }

    public function testAnUnexplainedDatabaseErrorStaysInternal(): void
    {
        $this->enter();

        $this->expectException(FaErrorException::class);
        ServiceCall::run(function () {
            db_query('SELECT * FROM no_such_table_write_plumbing', 'the probe query failed');
        });
    }

    public function testEachNamesTheFailingItemAndRollsBackTheWholeBatch(): void
    {
        $this->enter();
        $hashes = [$this->hash('a'), $this->hash('b'), $this->hash('c')];

        try {
            ServiceCall::each($hashes, function (string $hash, int $index) {
                $this->insert($hash);
                if ($index === 1) {
                    throw new BadInput('Name is required.', 'name');
                }
            });
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame(1, $e->index());
            $this->assertSame('name', $e->field());
        }
        foreach ($hashes as $hash) {
            $this->assertSame(0, $this->committed($hash));
        }
    }

    public function testEachTagsFrontAccountingsRefusalWithItsIndex(): void
    {
        $this->enter();

        try {
            ServiceCall::each(['a', 'b', 'c'], function (string $item, int $index) {
                if ($index === 2) {
                    display_error('The branch does not belong to the customer.');
                }
            });
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertSame(2, $e->getExtensions()['index']);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function ownRefusals(): array
    {
        return ['a guard' => ['FA_REJECTED'], 'a missing row' => ['NOT_FOUND']];
    }

    /**
     * Spec section 3.1: any refusal in a batch names the item's index — the services'
     * own guards and NOT_FOUND too, not only FrontAccounting's messages.
     *
     * @dataProvider ownRefusals
     */
    public function testEachTagsOurOwnRefusalsWithTheirIndexAndRollsBackTheBatch(string $code): void
    {
        $this->enter();
        $hashes = [$this->hash('a'), $this->hash('b')];

        try {
            ServiceCall::each($hashes, function (string $hash, int $index) use ($code) {
                $this->insert($hash);
                if ($index === 1) {
                    throw $code === 'NOT_FOUND'
                        ? new NotFound("Customer id '999999' not found")
                        : new FaRejected('Guarded.');
                }
            });
            $this->fail("expected $code");
        } catch (ApiError $e) {
            $this->assertSame($code, $e->code());
            $this->assertSame(1, $e->getExtensions()['index'] ?? null, json_encode($e->getExtensions()));
        }
        foreach ($hashes as $hash) {
            $this->assertSame(0, $this->committed($hash));
        }
    }

    public function testEachReturnsEveryResultInOrder(): void
    {
        $this->enter();

        $this->assertSame(['A0', 'B1'], ServiceCall::each(['a', 'b'], function (string $item, int $index) {
            return strtoupper($item) . $index;
        }));
    }

    public function testDatesRoundTripThroughFrontAccountingsUserFormat(): void
    {
        $this->enter();

        $fa = DateConversion::toFa('2026-09-25');
        $this->assertSame('2026-09-25', date2sql($fa));
        $this->assertSame('2026-09-25', DateConversion::fromFa($fa));
        $this->assertSame(
            '2026-02-28',
            DateConversion::fromFa(DateConversion::toFa(new \DateTimeImmutable('2026-02-28')))
        );
    }

    public function testIsActiveIsFalseUntilACompanyIsOpen(): void
    {
        $this->assertFalse($this->session()->isActive('graphql'));
    }

    public function testIsActiveNamesTheExtensionsActiveForTheCompany(): void
    {
        $session = $this->enter();

        $this->assertTrue($session->isActive('graphql'));
        $this->assertFalse($session->isActive('no_such_extension'));
        $active = array_column(array_filter($GLOBALS['installed_extensions'], function ($e) {
            return !empty($e['active']);
        }), 'package');
        $this->assertSame(in_array('sgw_sales', $active, true), $session->isActive('sgw_sales'));
    }
}
