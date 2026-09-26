<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Fa\DateConversion;

/**
 * Release 3 spec §2.2 and §2.3: the date and exchange-rate checks every billing
 * document makes before FrontAccounting writes it.
 */
final class BillingChecks
{
    /**
     * Deliveries, invoices and payments: is_date_in_fiscalyear()
     * (includes/date_functions.inc:192-216) — stricter than an order's check: not on
     * or before the GL closing date, and in the current fiscal year unless the user
     * holds SA_MULTIFISCALYEARS.
     */
    public static function assertInFiscalYear(string $isoDate, string $field, ?int $index = null): void
    {
        $faDate = DateConversion::toFa($isoDate, $field);
        if (!is_date_in_fiscalyear($faDate)) {
            throw new BadInput(
                "$isoDate is out of the fiscal year or closed for further data entry.",
                $field,
                $index
            );
        }
    }

    /**
     * FrontAccounting reports a missing rate through display_error() and then writes
     * with 1.0 (includes/banking.inc:30-45); an API refuses before anything is
     * written. db_has_currency_rates() (includes/data_checks.inc:44-55) passes the
     * company currency.
     */
    public static function assertExchangeRate(
        string $currency,
        string $isoDate,
        string $field,
        ?int $index = null
    ): void {
        $faDate = DateConversion::toFa($isoDate, $field);
        if (!db_has_currency_rates($currency, $faDate)) {
            throw new BadInput("There is no exchange rate for $currency as of $isoDate.", $field, $index);
        }
    }

    /**
     * A void is dated today (void_transaction.php's default); the page refuses a date
     * outside the fiscal year (admin/void_transaction.php:273-278).
     */
    public static function assertOpenToday(): void
    {
        if (!is_date_in_fiscalyear(\Today())) {
            $message = 'Today is out of the fiscal year or closed for further data entry: nothing can be voided.';
            throw new FaRejected($message, [$message]);
        }
    }
}
