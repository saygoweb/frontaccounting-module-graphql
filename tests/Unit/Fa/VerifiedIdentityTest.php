<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Fa\VerifiedIdentity;
use PHPUnit\Framework\TestCase;

class VerifiedIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        VerifiedIdentity::clear();
    }

    public function testNothingMatchesWhenUnset(): void
    {
        $this->assertFalse(VerifiedIdentity::matches(0, 'apitest'));
        $this->assertFalse(VerifiedIdentity::matches(0, ''));
    }

    public function testMatchesExactlyTheLoginAndCompanySet(): void
    {
        VerifiedIdentity::set(1, 'apitest');

        $this->assertTrue(VerifiedIdentity::matches(1, 'apitest'));
        $this->assertFalse(VerifiedIdentity::matches(0, 'apitest'));
        $this->assertFalse(VerifiedIdentity::matches(1, 'admin'));
        $this->assertFalse(VerifiedIdentity::matches(1, 'APITEST'));
    }

    public function testClear(): void
    {
        VerifiedIdentity::set(1, 'apitest');
        VerifiedIdentity::clear();

        $this->assertFalse(VerifiedIdentity::matches(1, 'apitest'));
    }

    public function testAnEmptyLoginNeverMatches(): void
    {
        VerifiedIdentity::set(0, '');

        $this->assertFalse(VerifiedIdentity::matches(0, ''));
    }
}
