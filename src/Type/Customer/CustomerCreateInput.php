<?php

namespace FA\GraphQL\Type\Customer;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Customer\Base\CustomerCreateInputBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Adds what the customer page creates with a new customer while auto_create_branch
 * is on: the default branch's references and the contact's details (spec §4.3).
 */
class CustomerCreateInput extends CustomerCreateInputBase
{
    private BranchDefaultsInput $branch;

    private ContactDetailsInput $contact;

    public function __construct(BranchDefaultsInput $branch, ContactDetailsInput $contact)
    {
        // Set before the parent constructor: it calls fields().
        $this->branch = $branch;
        $this->contact = $contact;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('branch', $this->branch)
                ->setDescription('Required while auto_create_branch is on; refused while it is off.')
                ->build(),
            FieldBuilder::create('contact', $this->contact)
                ->setDescription('Phone and email of the contact created with the default branch.')
                ->build(),
        ]);
    }
}
