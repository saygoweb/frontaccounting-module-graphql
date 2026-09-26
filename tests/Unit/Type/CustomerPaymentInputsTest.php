<?php

namespace FA\GraphQL\Tests\Unit\Type;

use FA\GraphQL\Type\CustomerPayment\AllocationInput;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentCreateInput;
use FA\GraphQL\Type\CustomerPayment\CustomerPaymentUpdateInput;
use GraphQL\Type\Definition\NonNull;
use PHPUnit\Framework\TestCase;

class CustomerPaymentInputsTest extends TestCase
{
    public function testTheCreateInputTakesTheBankSideAndAllocations(): void
    {
        $input = new CustomerPaymentCreateInput(new AllocationInput());
        $fields = $input->getFields();

        $taken = [
            'customerId', 'branchId', 'date', 'reference', 'amount', 'discount',
            'bankAccountId', 'bankAmount', 'charge', 'memo', 'allocations',
        ];
        foreach ($taken as $name) {
            $this->assertArrayHasKey($name, $fields, $name);
        }
        foreach (['transType', 'version', 'allocated', 'rate'] as $name) {
            $this->assertArrayNotHasKey($name, $fields, "$name is set by FrontAccounting");
        }
        $this->assertInstanceOf(NonNull::class, $fields['bankAccountId']->getType());
        $this->assertInstanceOf(NonNull::class, $fields['customerId']->getType());
        $this->assertInstanceOf(NonNull::class, $fields['amount']->getType());
    }

    public function testTheUpdateInputKeepsItsGeneratedFieldsAndAddsAllocations(): void
    {
        $input = new CustomerPaymentUpdateInput(new AllocationInput());
        $fields = $input->getFields();

        $this->assertInstanceOf(NonNull::class, $fields['id']->getType());
        $this->assertArrayHasKey('allocations', $fields);
        // The generated fields stay (generation wins); the service refuses them.
        $this->assertArrayHasKey('amount', $fields);
    }

    public function testAnAllocationNamesAnInvoiceAndAnAmount(): void
    {
        $fields = (new AllocationInput())->getFields();

        $this->assertSame(['invoiceId', 'amount'], array_keys($fields));
        $this->assertInstanceOf(NonNull::class, $fields['invoiceId']->getType());
        $this->assertInstanceOf(NonNull::class, $fields['amount']->getType());
    }
}
