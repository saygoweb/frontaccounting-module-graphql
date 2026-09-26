<?php

namespace FA\GraphQL\Type\BankAccount;

use FA\GraphQL\Type\BankAccount\Base\BankAccountTypeBase;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * Override points, all inherited from Anorm\GraphQL\ModelType:
 *  - authorize($verb, $model, $context)   throw to refuse 'list', 'create', 'edit' or 'delete'
 *  - beforeWrite($model, $input, $isUpdate, $context)   stamp columns before a write
 *  - newModel($context)   construct the model some other way
 *  - resolveList / resolveCreate / resolveUpdate / resolveDelete   replace a resolver outright
 *  - fields()   add computed fields: array_merge(parent::fields(), [...])
 */
class BankAccountType extends BankAccountTypeBase
{
    /**
     * Read-only: a payment names the account it is paid into, so listing needs the
     * area of entering payments (Release 3 spec §5), not SA_BANKACCOUNT, which
     * grants editing accounts in the web UI.
     *
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return ['list' => 'SA_SALESPAYMNT'];
    }
}
