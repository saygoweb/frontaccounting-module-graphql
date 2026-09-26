<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Fa\DateConversion;
use PHPUnit\Framework\TestCase;

class DateConversionTest extends TestCase
{
    public function testAnIsoDateStringIsKept(): void
    {
        $this->assertSame('2026-09-25', DateConversion::iso('2026-09-25'));
    }

    public function testADateObjectBecomesIso(): void
    {
        $this->assertSame('2026-02-28', DateConversion::iso(new \DateTimeImmutable('2026-02-28 13:45:00')));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function notDates(): array
    {
        return [
            'impossible day' => ['2026-02-30'],
            'user format' => ['25/09/2026'],
            'datetime string' => ['2026-09-25 10:00:00'],
            'trailing newline' => ["2026-09-25\n"],
            'empty' => [''],
            'integer' => [20260925],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider notDates
     * @param mixed $value
     */
    public function testAnythingElseIsBadInputNamingTheField($value): void
    {
        try {
            DateConversion::iso($value, 'orderDate');
            $this->fail('expected BadInput');
        } catch (BadInput $e) {
            $this->assertSame('Expected a date as YYYY-MM-DD.', $e->getMessage());
            $this->assertSame('orderDate', $e->field());
        }
    }

    public function testFromSql(): void
    {
        $this->assertNull(DateConversion::fromSql(null));
        $this->assertNull(DateConversion::fromSql(''));
        $this->assertNull(DateConversion::fromSql('0000-00-00'));
        $this->assertSame('2026-09-25', DateConversion::fromSql('2026-09-25'));
        $this->assertSame('2026-09-25', DateConversion::fromSql('2026-09-25 10:11:12'));
    }

    public function testAMalformedSqlDateIsAnInternalError(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        DateConversion::fromSql('garbage');
    }
}
