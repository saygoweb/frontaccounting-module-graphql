<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Config;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;

/**
 * Release 3 spec §2.1: document writes are serialised per company by a named lock,
 * held across the whole FrontAccounting transaction.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class DocumentLockTest extends FaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // No container, no login: db_query() needs a company's connection, which
        // openCompany() sets up (set_global_connection()); DocumentLock itself needs
        // nobody signed in.
        $session = new FaSession(Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef']));
        $session->openCompany(0);
    }

    public function testTheLockIsHeldWhileTheWorkRunsAndReleasedAfter(): void
    {
        $other = FaTestRows::connect();
        $name = DocumentLock::name();

        $seen = DocumentLock::run(function () use ($other, $name): string {
            // IS_USED_LOCK: the connection id holding it, or NULL.
            return (string) $other->query('SELECT IS_USED_LOCK(' . $other->quote($name) . ')')->fetchColumn();
        });

        $this->assertNotSame('', $seen, 'held while the work runs');
        $this->assertNull($other->query('SELECT IS_USED_LOCK(' . $other->quote($name) . ')')->fetchColumn());
    }

    public function testTheLockIsReleasedWhenTheWorkThrows(): void
    {
        $other = FaTestRows::connect();
        try {
            DocumentLock::run(function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('the work\'s exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertNull(
            $other->query('SELECT IS_USED_LOCK(' . $other->quote(DocumentLock::name()) . ')')->fetchColumn()
        );
    }

    public function testAFailedReleaseIsLoggedAndTheWorksErrorWins(): void
    {
        // Checkpoint B M-5: the connection dies inside the work, so RELEASE_LOCK
        // fails too; the lock went with the connection.
        $other = FaTestRows::connect();
        $log = tempnam(sys_get_temp_dir(), 'fa-graphql-lock');
        $previous = ini_set('error_log', (string) $log);
        try {
            DocumentLock::run(function () use ($other): void {
                $id = (int) db_fetch_row(db_query('SELECT CONNECTION_ID()', 'could not read'))[0];
                $other->exec('KILL ' . $id);
                throw new \RuntimeException('boom');
            });
            $this->fail('the work\'s exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents((string) $log);
        unlink((string) $log);
        $this->assertStringContainsString('could not release the document lock', $logged);
        $this->assertSame('1', (string) $other->query(
            'SELECT IS_FREE_LOCK(' . $other->quote(DocumentLock::name()) . ')'
        )->fetchColumn());
    }

    public function testASecondWriterWaitsAndThenIsRefusedAsBusy(): void
    {
        $other = FaTestRows::connect();
        $this->assertSame('1', (string) $other->query(
            'SELECT GET_LOCK(' . $other->quote(DocumentLock::name()) . ', 0)'
        )->fetchColumn());
        try {
            DocumentLock::run(function (): void {
                $this->fail('must not run while another connection holds the lock');
            }, 1);
            $this->fail('expected FaRejected');
        } catch (FaRejected $e) {
            $this->assertSame('FrontAccounting is busy; try again.', $e->getMessage());
        } finally {
            $other->query('SELECT RELEASE_LOCK(' . $other->quote(DocumentLock::name()) . ')');
        }
    }

    public function testTheLockIsPerCompany(): void
    {
        $this->assertSame('fa_graphql_docs_0', DocumentLock::name());
    }
}
