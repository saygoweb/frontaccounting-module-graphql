<?php

namespace FA\GraphQL\Tests\Support;

/**
 * Every FrontAccounting posting balances: the gl_trans rows of one document sum to
 * zero (Release 3 spec §8). Needs $this->pdo() (FaTestCase).
 */
trait AssertsGlBalanced
{
    /**
     * @return array<int, array<string, string|null>>
     */
    protected function glRows(int $type, int $transNo): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT account, amount FROM 0_gl_trans WHERE type = ? AND type_no = ? ORDER BY counter'
        );
        $statement->execute([$type, $transNo]);

        return array_map(static function (array $row): array {
            return array_map(static function ($value): ?string {
                return $value === null ? null : (string) $value;
            }, $row);
        }, $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    protected function assertGlBalanced(int $type, int $transNo): void
    {
        $sum = 0.0;
        foreach ($this->glRows($type, $transNo) as $row) {
            $sum += (float) $row['amount'];
        }
        $this->assertEqualsWithDelta(0.0, $sum, 0.005, "gl_trans for $type/$transNo must balance");
    }
}
