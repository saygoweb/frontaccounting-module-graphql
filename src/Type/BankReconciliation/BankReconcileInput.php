<?php

namespace FA\GraphQL\Type\BankReconciliation;

use Anorm\GraphQL\Type\DateType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * bankReconcile's input (Bank reconciliation spec §3.3).
 */
final class BankReconcileInput extends InputObjectType
{
    public function __construct()
    {
        parent::__construct([
            'name' => 'BankReconcileInput',
            'fields' => [
                'bankAccountId' => ['type' => Type::nonNull(Type::id())],
                'statementDate' => ['type' => Type::nonNull(DateType::instance())],
                'endingBalance' => [
                    'type' => Type::nonNull(Type::float()),
                    'description' => "The statement's closing balance.",
                ],
                'reconcile' => [
                    'type' => Type::listOf(Type::nonNull(Type::id())),
                    'description' => 'Bank entry ids (bankTransactionList) to tick to statementDate.',
                ],
                'unreconcile' => [
                    'type' => Type::listOf(Type::nonNull(Type::id())),
                    'description' => 'Bank entry ids to untick from statementDate.',
                ],
            ],
        ]);
    }
}
