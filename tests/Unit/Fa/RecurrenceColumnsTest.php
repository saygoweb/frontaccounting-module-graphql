<?php

namespace FA\GraphQL\Tests\Unit\Fa;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Fa\Service\RecurringSchedule;
use PHPUnit\Framework\TestCase;

/**
 * RecurrenceInput <-> sgw_sales' sales_recurring columns (Release 2 spec section 4.5;
 * sgw_sales sales_order_entry.php :549-576).
 */
class RecurrenceColumnsTest extends TestCase
{
    public function testMonthlyStoresTheDayAsOccur(): void
    {
        $columns = RecurringSchedule::toColumns([
            'start' => new \DateTimeImmutable('2026-10-01'),
            'repeats' => 'month',
            'every' => 1,
            'day' => 15,
        ]);

        $this->assertSame([
            'dt_start' => '2026-10-01',
            'dt_end' => null,
            'auto' => 1,
            'every' => 1,
            'repeats' => 'month',
            'occur' => '15',
        ], $columns);
    }

    public function testYearlyStoresMonthDayAsOccurAndTakesAnEnd(): void
    {
        $columns = RecurringSchedule::toColumns([
            'start' => '2026-01-01',
            'end' => '2030-12-31',
            'repeats' => 'year',
            'every' => 2,
            'monthDay' => '02-29',
            'auto' => false,
        ]);

        $this->assertSame('02-29', $columns['occur']);
        $this->assertSame('2030-12-31', $columns['dt_end']);
        $this->assertSame(0, $columns['auto']);
        $this->assertSame(2, $columns['every']);
    }

    /**
     * @dataProvider invalid
     */
    public function testAnInvalidScheduleNamesItsField(array $recurrence, string $field): void
    {
        try {
            RecurringSchedule::toColumns($recurrence);
            $this->fail('accepted');
        } catch (BadInput $e) {
            $this->assertSame($field, $e->field(), $e->getMessage());
        }
    }

    public function invalid(): array
    {
        $monthly = ['start' => '2026-10-01', 'repeats' => 'month', 'every' => 1, 'day' => 1];
        $yearly = ['start' => '2026-10-01', 'repeats' => 'year', 'every' => 1, 'monthDay' => '10-01'];

        return [
            'monthly without a day' => [array_merge($monthly, ['day' => null]), 'recurring.day'],
            'day 0' => [array_merge($monthly, ['day' => 0]), 'recurring.day'],
            'day 32' => [array_merge($monthly, ['day' => 32]), 'recurring.day'],
            'monthly with monthDay' => [array_merge($monthly, ['monthDay' => '01-01']), 'recurring.monthDay'],
            'yearly without monthDay' => [array_merge($yearly, ['monthDay' => null]), 'recurring.monthDay'],
            'yearly 13-01' => [array_merge($yearly, ['monthDay' => '13-01']), 'recurring.monthDay'],
            'yearly 02-30' => [array_merge($yearly, ['monthDay' => '02-30']), 'recurring.monthDay'],
            'yearly with day' => [array_merge($yearly, ['day' => 1]), 'recurring.day'],
            'every 0' => [array_merge($monthly, ['every' => 0]), 'recurring.every'],
            'every 128 (tinyint)' => [array_merge($monthly, ['every' => 128]), 'recurring.every'],
            'unknown repeats' => [array_merge($monthly, ['repeats' => 'week']), 'recurring.repeats'],
            'no start' => [array_merge($monthly, ['start' => null]), 'recurring.start'],
            'ends before it starts' => [array_merge($monthly, ['end' => '2026-09-30']), 'recurring.end'],
        ];
    }

    public function testARowReadsBackAsARecurrence(): void
    {
        $this->assertSame([
            'start' => '2026-10-01',
            'end' => null,
            'next' => '2026-11-15',
            'repeats' => 'month',
            'every' => 1,
            'day' => 15,
            'monthDay' => null,
            'auto' => true,
        ], RecurringSchedule::fromRow([
            'id' => '7', 'trans_no' => '12', 'dt_start' => '2026-10-01', 'dt_end' => null,
            'dt_next' => '2026-11-15', 'auto' => '1', 'every' => '1', 'repeats' => 'month', 'occur' => '15',
        ]));
        $this->assertSame('03-01', RecurringSchedule::fromRow([
            'dt_start' => '2026-03-01', 'dt_end' => '0000-00-00', 'dt_next' => null,
            'auto' => '0', 'every' => '1', 'repeats' => 'year', 'occur' => '03-01',
        ])['monthDay']);
    }
}
