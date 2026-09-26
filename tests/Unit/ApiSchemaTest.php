<?php

namespace FA\GraphQL\Tests\Unit;

use DI\ContainerBuilder;
use FA\GraphQL\ApiSchema;
use GraphQL\Utils\SchemaPrinter;
use PHPUnit\Framework\TestCase;

class ApiSchemaTest extends TestCase
{
    private function schema(): ApiSchema
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        return new ApiSchema($builder->build());
    }

    public function testTheSchemaIsValid(): void
    {
        $this->schema()->assertValid();
        $this->addToAssertionCount(1);
    }

    public function testTheSurfaceIsWhatTheSpecSays(): void
    {
        $sdl = SchemaPrinter::doPrint($this->schema());

        $this->assertStringContainsString('apiVersion: String!', $sdl);
        $this->assertStringContainsString('me: Viewer!', $sdl);
        $this->assertStringContainsString(
            'login(company: Int = 0, user: String!, password: String!): AuthPayload!',
            $sdl
        );
        $this->assertStringContainsString('tokenRefresh(refreshToken: String!): AuthPayload!', $sdl);
        $this->assertStringContainsString('tokenRevoke(refreshToken: String): Boolean!', $sdl);
        $this->assertMatchesRegularExpression(
            '/type AuthPayload \{\s+accessToken: String!\s+expiresIn: Int!\s+refreshToken: String!\s+\}/',
            $sdl
        );
        $viewerFields = [
            'login: String!', 'name: String!', 'email: String',
            'company: Int!', 'companyName: String!', 'areas: [String!]!',
        ];
        foreach ($viewerFields as $field) {
            $this->assertStringContainsString($field, $sdl);
        }
    }

    /**
     * Foundation spec §3.1 plus Release 2's lookups (Release 2 spec §4.2): exactly
     * these root fields and nothing else. A new root field is a spec change, not a
     * drive-by. Order is testQueryAndMutationFieldsAreAlphabetical's business.
     */
    public function testTheRootFieldsAreExactlyTheSpecsAndNothingElse(): void
    {
        $schema = $this->schema();
        $query = array_keys($schema->getQueryType()->getFields());
        sort($query);
        $expected = [
            'apiVersion', 'branchList', 'contactList', 'creditStatusList', 'currencyList', 'customerList',
            'locationList', 'me', 'paymentTermsList', 'salesAreaList', 'salesTypeList', 'salesmanList',
            'shipperList', 'stockItemList', 'taxGroupList',
        ];
        sort($expected);

        $this->assertSame($expected, $query);
        $this->assertSame(
            [
                'branchCreate', 'branchDelete', 'branchUpdate', 'contactCreate', 'contactDelete', 'contactUpdate',
                'customerCreate', 'customerDelete', 'customerUpdate', 'login', 'tokenRefresh', 'tokenRevoke',
            ],
            array_keys($schema->getMutationType()->getFields())
        );
        $this->assertNull($schema->getSubscriptionType());
    }

    public function testSalesTypesAreListedReadOnlyThroughMango(): void
    {
        $sdl = SchemaPrinter::doPrint($this->schema());

        $this->assertStringContainsString('salesTypeList(query: MangoInput): [SalesTypeType!]!', $sdl);
        $typePattern = '/type SalesTypeType \{\s+id: ID!\s+name: String\s+taxIncluded: Boolean'
            . '\s+factor: Float\s+inactive: Boolean\s+\}/';
        $this->assertMatchesRegularExpression($typePattern, $sdl);
        $inputPattern = '/input MangoInput \{\s+action: String\s+selector: String\s+limit: Int'
            . '\s+skip: Int\s+sort: \[String\]\s+\}/';
        $this->assertMatchesRegularExpression($inputPattern, $sdl);
        // Read-only: no Input, no mutations.
        $this->assertStringNotContainsString('SalesTypeInput', $sdl);
        $this->assertStringNotContainsString('salesTypeUpsert', $sdl);
        $this->assertStringNotContainsString('salesTypeDelete', $sdl);
    }

    public function testQueryAndMutationFieldsAreAlphabetical(): void
    {
        foreach (['Query', 'Mutation'] as $type) {
            $names = array_keys($this->schema()->getType($type)->getFields());
            $sorted = $names;
            sort($sorted);
            $this->assertSame($sorted, $names, $type);
        }
    }

    public function testTheFieldsArraysAreInTheShapeTheGeneratorEdits(): void
    {
        // anorm-graphql edits `'query' => new ObjectType([ ... 'fields' => [ ... ] ])`
        // in place, and changes nothing in a file of any other shape.
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/ApiSchema.php');

        $this->assertMatchesRegularExpression(
            "/'query' => new ObjectType\\(\\[\\s+'name' => 'Query',\\s+'fields' => \\[\\n/",
            $source
        );
        $this->assertMatchesRegularExpression(
            "/'mutation' => new ObjectType\\(\\[\\s+'name' => 'Mutation',\\s+'fields' => \\[\\n/",
            $source
        );
        $this->assertStringContainsString('private function type(string $name)', $source);
    }
}
