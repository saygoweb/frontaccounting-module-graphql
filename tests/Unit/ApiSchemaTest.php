<?php

namespace FA\GraphQL\Tests\Unit;

use DI\ContainerBuilder;
use FA\GraphQL\ApiSchema;
use FA\GraphQL\Db\UnconnectedPdo;
use FA\GraphQL\Type\FaModelType;
use GraphQL\Type\Definition\Type;
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
     * Foundation spec §3.1 plus Release 2's lookups (Release 2 spec §4.2), plus
     * Release 2's generated entities (Release 2 spec section 4): exactly these root
     * fields and nothing else. A new root field is a spec change, not a
     * drive-by. Order is testQueryAndMutationFieldsAreAlphabetical's business.
     */
    public function testTheRootFieldsAreExactlyTheSpecsAndNothingElse(): void
    {
        $schema = $this->schema();
        $query = array_keys($schema->getQueryType()->getFields());
        sort($query);
        $lists = [
            'allocation', 'bankAccount', 'branch', 'contact', 'creditStatus', 'currency', 'customer',
            'customerPayment', 'deliveryLine', 'delivery', 'invoiceLine', 'invoice', 'location', 'paymentTerms',
            'salesArea', 'salesOrderLine', 'salesOrder', 'salesType', 'salesman', 'shipper', 'stockItem',
            'taxGroup',
        ];
        $expected = ['apiVersion', 'me'];
        foreach ($lists as $entity) {
            // Issue #17: every list has a count beside it.
            $expected[] = $entity . 'List';
            $expected[] = $entity . 'Count';
        }
        sort($expected);

        $this->assertSame($expected, $query);
        $this->assertSame(
            [
                'branchCreate', 'branchDelete', 'branchUpdate', 'contactCreate', 'contactDelete', 'contactUpdate',
                'customerCreate', 'customerDelete', 'customerPaymentCreate', 'customerPaymentDelete',
                'customerPaymentUpdate', 'customerUpdate', 'deliveryCreate', 'deliveryDelete',
                'invoiceCreate', 'invoiceDelete', 'invoiceEmail', 'login', 'salesOrderCreate', 'salesOrderDelete',
                'salesOrderUpdate',
                'tokenRefresh', 'tokenRevoke',
            ],
            array_keys($schema->getMutationType()->getFields())
        );
        $this->assertNull($schema->getSubscriptionType());
    }

    public function testSalesTypesAreListedReadOnlyThroughMango(): void
    {
        $sdl = SchemaPrinter::doPrint($this->schema());

        // The query argument's description is its queryable fields (issue #21).
        $this->assertMatchesRegularExpression(
            '/salesTypeList\(\s+"Fields for selector and sort: id, name, taxIncluded, factor, inactive\. [^"]*"'
            . '\s+query: MangoInput\s+\): \[SalesTypeType!\]!/',
            $sdl
        );
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

    /**
     * Issue #17: <entity>Count(query: MangoInput): Int! beside every <entity>List that
     * takes a query, so a client paging a list can learn its total.
     */
    public function testEveryListWithAQueryHasACountBesideIt(): void
    {
        $fields = $this->schema()->getQueryType()->getFields();
        $lists = 0;
        foreach ($fields as $name => $field) {
            if (substr($name, -4) !== 'List' || $field->getArg('query') === null) {
                continue;
            }
            $lists++;
            $countName = substr($name, 0, -4) . 'Count';
            $this->assertArrayHasKey($countName, $fields, $name);
            $count = $fields[$countName];
            $this->assertSame('Int!', (string) $count->getType(), $countName);
            $this->assertSame(['query'], array_map(function ($arg): string {
                return $arg->name;
            }, $count->args), $countName);
            $this->assertSame('MangoInput', (string) $count->getArg('query')->getType(), $countName);
            $this->assertStringContainsString($name, (string) $count->description, $countName);
        }
        $this->assertSame(22, $lists);
    }

    /**
     * Issue #21: the fields a list's query.selector and query.sort accept are in the
     * description of its query argument, and of its count's. invoiceList's computed
     * fields are not among them: they are refused with BAD_INPUT.
     */
    public function testTheInvoiceListsQueryDescriptionNamesExactlyItsQueryableFields(): void
    {
        $fields = $this->schema()->getQueryType()->getFields();
        $expected = [
            'id', 'transType', 'version', 'customerId', 'branchId', 'date', 'dueDate', 'reference',
            'salesTypeId', 'orderId', 'amount', 'tax', 'freight', 'freightTax', 'discount', 'allocated',
            'prepaymentAmount', 'rate', 'shipperId', 'paymentTermsId', 'taxIncluded',
        ];
        foreach (['invoiceList', 'invoiceCount'] as $name) {
            $description = (string) $fields[$name]->getArg('query')->description;
            $named = self::namedFields($description);
            $this->assertSame($expected, $named, $name);
            foreach (['total', 'outstanding', 'voided', 'lines'] as $computed) {
                $this->assertNotContains($computed, $named, "$name: $computed");
            }
            $this->assertStringContainsString('Only these fields', $description, $name);
        }
    }

    /**
     * Issue #21: every list's query description, and its count's, names its model's
     * properties: the map ModelType::assertFieldName() checks a selector and a sort
     * against.
     */
    public function testEveryListsQueryDescriptionNamesItsModelsProperties(): void
    {
        $fields = $this->schema()->getQueryType()->getFields();
        $lists = 0;
        foreach ($fields as $name => $field) {
            if (substr($name, -4) !== 'List' || $field->getArg('query') === null) {
                continue;
            }
            $lists++;
            $type = Type::getNamedType($field->getType());
            $this->assertInstanceOf(FaModelType::class, $type, $name);
            $expected = self::modelProperties($type);
            $this->assertSame($expected, self::namedFields((string) $field->getArg('query')->description), $name);
            $count = $fields[substr($name, 0, -4) . 'Count'];
            $this->assertSame(
                $field->getArg('query')->description,
                $count->getArg('query')->description,
                $name . ' and its count'
            );
        }
        $this->assertSame(22, $lists);
    }

    /**
     * @return string[] the names a query description lists
     */
    private static function namedFields(string $description): array
    {
        if (!preg_match('/Fields for selector and sort: ([^.]+)\./', $description, $m)) {
            return [];
        }

        return array_map('trim', explode(',', $m[1]));
    }

    /**
     * @return string[] the property names of $type's model, read from a model built
     *                  on a PDO that never connects
     */
    private static function modelProperties(Type $type): array
    {
        $method = new \ReflectionMethod($type, 'modelClass');
        $method->setAccessible(true);
        $class = $method->invoke($type);
        return array_keys((new $class(new UnconnectedPdo()))->mapper()->map);
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
