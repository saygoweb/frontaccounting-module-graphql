<?php

namespace FA\GraphQL\Type\BankReconciliation;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A bank account's reconciliation at a statement date, as the Reconcile Bank Account
 * screen's summary shows it (Bank reconciliation spec §3.2).
 */
final class BankReconciliationType extends ObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'BankReconciliation',
            'fields' => [
                'bankAccountId' => ['type' => Type::nonNull(Type::id())],
                'statementDate' => ['type' => Type::nonNull(DateType::instance())],
                'beginningBalance' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Reconciled to statements before this one.',
                ],
                'reconciledAmount' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Reconciled to this statement.',
                ],
                'endingBalance' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'beginningBalance plus reconciledAmount.',
                ],
                'accountTotal' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => 'Every entry of the account, reconciled or not.',
                ],
                'lastReconciledDate' => [
                    'type' => DateType::instance(),
                    'description' => 'The statement the account last recorded.',
                ],
                'storedEndingBalance' => [
                    'type' => Type::float(),
                    'description' => "The statement's closing balance as recorded, when lastReconciledDate is "
                        . 'this statement.',
                ],
                'difference' => [
                    'type' => Type::float(),
                    'description' => 'storedEndingBalance less endingBalance: 0 when the statement is reconciled; '
                        . 'null without a recorded closing balance.',
                ],
                'statementDates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(DateType::instance()))),
                    'description' => "The account's statement dates, newest first.",
                ],
            ],
        ]);
    }
}
