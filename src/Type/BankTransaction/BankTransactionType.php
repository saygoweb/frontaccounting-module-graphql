<?php

namespace FA\GraphQL\Type\BankTransaction;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\Service\FaIncludes;
use FA\GraphQL\Type\BankTransaction\Base\BankTransactionTypeBase;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Bank entries, read-only (Bank reconciliation spec §3.1): listed with
 * SA_BANKTRANSVIEW, FrontAccounting's "Bank transactions view". The memo and the
 * counterparty are worked out per row, as the Reconcile Bank Account screen shows
 * them (gl/bank_account_reconcile.php: fmt_memo, fmt_person).
 */
class BankTransactionType extends BankTransactionTypeBase
{
    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [self::VERB_LIST => 'SA_BANKTRANSVIEW'];
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('personId', Type::string())
                ->setDescription('An id, or for a misc entry a free-text name.')
                ->build(),
            FieldBuilder::create('voided', Type::nonNull(Type::boolean()))
                ->setDescription('Amount 0: FrontAccounting voids a bank entry by zeroing it.')
                ->setResolver(function (array $row): bool {
                    return (bool) $row['voided'];
                })
                ->build(),
            FieldBuilder::create('memo', Type::string())
                ->setDescription('The transaction\'s memo: its comments, joined as FrontAccounting\'s '
                    . 'get_comments_string() joins them.')
                ->setResolver(function (array $row, $args, $context): ?string {
                    // get_comments_string() (includes/ui/ui_view.inc) over get_comments()'s query.
                    $statement = $context->get(\PDO::class)->prepare(
                        'SELECT memo_ FROM ' . CompanyContext::prefix() . 'comments WHERE type = ? AND id = ?'
                    );
                    $statement->execute([(int) $row['transType'], (int) $row['transNo']]);
                    $memo = implode(" \n", array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));

                    return $memo === '' ? null : $memo;
                })
                ->build(),
            FieldBuilder::create('counterparty', Type::string())
                ->setDescription('Who the money came from or went to: the customer, supplier, quick entry or '
                    . 'misc name, as FrontAccounting\'s payment_person_name() gives it.')
                ->setResolver(function (array $row): ?string {
                    FaIncludes::banking();
                    // payment_person_name() reads a missing quick entry's description off false.
                    if ((int) $row['personType'] === PT_QUICKENTRY && !\get_quick_entry($row['personId'])) {
                        return null;
                    }
                    $name = \payment_person_name((int) $row['personType'], (string) $row['personId'], false);

                    return $name === '' || $name === null ? null : (string) $name;
                })
                ->build(),
        ]);
    }
}
