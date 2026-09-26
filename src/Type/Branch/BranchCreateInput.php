<?php

namespace FA\GraphQL\Type\Branch;

use Anorm\GraphQL\Builder\FieldBuilder;
use FA\GraphQL\Type\Branch\Base\BranchCreateInputBase;

/**
 * Yours to edit: anorm-graphql wrote this file once and never again.
 *
 * Adds the CRM person FrontAccounting creates with every new branch (spec §4.3).
 */
class BranchCreateInput extends BranchCreateInputBase
{
    private BranchContactInput $contact;

    public function __construct(BranchContactInput $contact)
    {
        $this->contact = $contact;
        parent::__construct();
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('contact', $this->contact)
                ->setDescription("The branch's contact person, created with it.")
                ->build(),
        ]);
    }
}
