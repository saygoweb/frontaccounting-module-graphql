<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Fa\CompanyContext;
use PHPUnit\Framework\TestCase;

class CompanyContextTest extends TestCase
{
    protected function tearDown(): void
    {
        CompanyContext::reset();
    }

    public function testUnsetAnswersTheGenerationDefaultsAndNeverQueries(): void
    {
        $this->assertFalse(CompanyContext::isSet());
        $this->assertSame(0, CompanyContext::company());
        $this->assertSame('0_', CompanyContext::prefix());
    }

    public function testUnsetHasNoCredentials(): void
    {
        $this->expectException(\LogicException::class);
        CompanyContext::credentials();
    }

    public function testSet(): void
    {
        $connection = [
            'name' => 'Acme', 'host' => 'db', 'dbuser' => 'u', 'dbpassword' => 'p', 'dbname' => 'fa', 'tbpref' => '3_',
        ];

        CompanyContext::set(3, $connection, 'latin1');

        $this->assertTrue(CompanyContext::isSet());
        $this->assertSame(3, CompanyContext::company());
        $this->assertSame('3_', CompanyContext::prefix());
        $this->assertSame('Acme', CompanyContext::name());
        $this->assertSame('latin1', CompanyContext::charset());
        $this->assertSame($connection, CompanyContext::credentials());
    }

    public function testAnEmptyPrefixIsRespected(): void
    {
        CompanyContext::set(1, [
            'host' => 'db', 'dbuser' => 'u', 'dbpassword' => 'p', 'dbname' => 'fa', 'tbpref' => '',
        ]);

        $this->assertSame('', CompanyContext::prefix());
    }

    public function testResetReturnsToDefaults(): void
    {
        CompanyContext::set(3, [
            'host' => 'db', 'dbuser' => 'u', 'dbpassword' => 'p', 'dbname' => 'fa', 'tbpref' => '3_',
        ]);
        CompanyContext::reset();

        $this->assertFalse(CompanyContext::isSet());
        $this->assertSame('0_', CompanyContext::prefix());
    }
}
