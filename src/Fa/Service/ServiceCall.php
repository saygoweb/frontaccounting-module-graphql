<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\FaErrorException;
use FA\GraphQL\Fa\FaMessages;
use FA\GraphQL\Fa\FaTransaction;
use FA\GraphQL\Fa\Warnings;

/**
 * How every service method calls FrontAccounting (Release 2 spec sections 2.1, 3):
 * one transaction, FrontAccounting's error messages turned into FA_REJECTED (and the
 * work rolled back), its warnings reported once the work has committed.
 */
final class ServiceCall
{
    /**
     * @return mixed what $work returns
     */
    public static function run(callable $work)
    {
        $warnings = [];
        $result = FaTransaction::run(function () use ($work, &$warnings) {
            FaMessages::reset();
            try {
                $result = $work();
            } catch (FaErrorException $e) {
                throw self::rejected($e, null);
            }
            $warnings = self::check(null);

            return $result;
        });
        self::report($warnings);

        return $result;
    }

    /**
     * A batch — one generated mutation's input list — in one transaction: any refusal
     * rolls back every item, and the error names the item's index.
     *
     * @param array<int|string, mixed> $inputs
     * @return array<int, mixed> $work's results, in order
     */
    public static function each(array $inputs, callable $work): array
    {
        $warnings = [];
        $results = FaTransaction::run(function () use ($inputs, $work, &$warnings) {
            FaMessages::reset();
            $results = [];
            foreach (array_values($inputs) as $index => $input) {
                try {
                    $results[] = $work($input, $index);
                } catch (BadInput | FaRejected | NotFound $e) {
                    // The services' own refusals and guards, not only FrontAccounting's
                    // messages, name the item (spec section 3.1).
                    throw $e->index() === null ? $e->withIndex($index) : $e;
                } catch (FaErrorException $e) {
                    throw self::rejected($e, $index);
                }
                $warnings = array_merge($warnings, self::check($index));
            }

            return $results;
        });
        self::report($warnings);

        return $results;
    }

    /**
     * @return string[] the warnings, once errors have been ruled out
     */
    private static function check(?int $index): array
    {
        $messages = FaMessages::drainByLevel();
        if ($messages['errors'] !== []) {
            throw new FaRejected($messages['errors'][0], $messages['errors'], $index);
        }

        return $messages['warnings'];
    }

    /**
     * A database error FrontAccounting explained (its duplicate-key warning, say) is
     * a refusal the client can act on; one it did not explain stays INTERNAL.
     */
    private static function rejected(FaErrorException $e, ?int $index): \Throwable
    {
        $messages = FaMessages::drainByLevel();
        $texts = array_merge($messages['errors'], $messages['warnings']);

        return $texts === [] ? $e : new FaRejected($texts[0], $texts, $index);
    }

    /**
     * @param string[] $warnings
     */
    private static function report(array $warnings): void
    {
        foreach ($warnings as $warning) {
            Warnings::add($warning);
        }
    }
}
