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
                ->setDescription('The transaction\'s memo (comments).')
                ->setResolver(function (array $row, $args, $context): ?string {
                    $statement = $context->get(\PDO::class)->prepare(
                        'SELECT memo_ FROM ' . CompanyContext::prefix()
                        . 'comments WHERE type = ? AND id = ? ORDER BY date_ LIMIT 1'
                    );
                    $statement->execute([(int) $row['transType'], (int) $row['transNo']]);
                    $memo = $statement->fetchColumn();

                    return $memo === false ? null : (string) $memo;
                })
                ->build(),
            FieldBuilder::create('counterparty', Type::string())
                ->setDescription('Who the money came from or went to: the customer, supplier, quick entry or '
                    . 'misc name, as FrontAccounting\'s payment_person_name() gives it.')
                ->setResolver(function (array $row): ?string {
                    FaIncludes::banking();
                    $name = \payment_person_name((int) $row['personType'], (string) $row['personId'], false);

                    return $name === '' || $name === null ? null : (string) $name;
                })
                ->build(),
        ]);
    }
}
