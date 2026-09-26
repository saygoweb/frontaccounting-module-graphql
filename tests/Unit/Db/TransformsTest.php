<?php

namespace FA\GraphQL\Tests\Unit\Db;

use FA\GraphQL\Db\PercentTransform;
use FA\GraphQL\Db\SqlDateTransform;
use PHPUnit\Framework\TestCase;

class TransformsTest extends TestCase
{
    public function testADateColumnBecomesAnImmutableDate(): void
    {
        $date = (new SqlDateTransform())->txDatabaseToModel('2026-09-25');

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2026-09-25', $date->format('Y-m-d'));
    }

    /**
     * FrontAccounting's NOT NULL date columns default to the zero date; Anorm's own
     * SqlDateTimeTransform would make it -0001-11-30.
     *
     * @dataProvider noDates
     */
    public function testTheZeroDateAndNullAreNull($value): void
    {
        $this->assertNull((new SqlDateTransform())->txDatabaseToModel($value));
    }

    public function noDates(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'zero date' => ['0000-00-00'],
            'zero datetime' => ['0000-00-00 00:00:00'],
        ];
    }

    public function testADateGoesBackAsYmd(): void
    {
        $transform = new SqlDateTransform();

        $this->assertSame('2026-01-31', $transform->txModelToDatabase(new \DateTimeImmutable('2026-01-31')));
        $this->assertSame('2026-01-31', $transform->txModelToDatabase('2026-01-31'));
        $this->assertNull($transform->txModelToDatabase(null));
    }

    public function testAFractionIsAPercentAndBack(): void
    {
        $transform = new PercentTransform();

        $this->assertSame(12.5, $transform->txDatabaseToModel('0.125'));
        $this->assertSame(0.0, $transform->txDatabaseToModel(0));
        $this->assertSame(0.125, $transform->txModelToDatabase(12.5));
        $this->assertNull($transform->txDatabaseToModel(null));
    }
}
