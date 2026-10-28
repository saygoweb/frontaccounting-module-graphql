<?php

namespace FA\GraphQL\Tests\Unit;

use Anorm\GraphQL\ModelType;
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
        $this->assertStringContainsString(
            'priceQuote(stockId: String!, customerId: ID, salesTypeId: ID): PriceQuote!',
            $sdl
        );
        $this->assertStringContainsString('salesTypeId: ID!', $sdl);
        $this->assertStringContainsString('priceSet(input: [PriceSetInput!]!): [PriceType!]!', $sdl);
        $this->assertStringContainsString('priceDelete(input: [PriceKeyInput!]!): [PriceType!]!', $sdl);
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
            'allocation', 'bankAccount', 'bankTransaction', 'branch', 'contact', 'creditStatus', 'currency', 'customer',
            'customerPayment', 'deliveryLine', 'delivery', 'invoiceLine', 'invoice', 'location', 'paymentTerms',
            'price', 'salesArea', 'salesOrderLine', 'salesOrder', 'salesType', 'salesman', 'shipper', 'stockItem',
            'taxGroup',
        ];
        $expected = ['apiVersion', 'bankReconciliation', 'me', 'priceQuote'];
        foreach ($lists as $entity) {
            // Issue #17: every list has a count beside it.
            $expected[] = $entity . 'List';
            $expected[] = $entity . 'Count';
        }
        sort($expected);

        $this->assertSame($expected, $query);
        $this->assertSame(
            [
                'bankReconcile', 'branchCreate', 'branchDelete', 'branchUpdate', 'contactCreate', 'contactDelete',
                'contactUpdate',
                'customerCreate', 'customerDelete', 'customerPaymentCreate', 'customerPaymentDelete',
                'customerPaymentUpdate', 'customerUpdate', 'deliveryCreate', 'deliveryDelete',
                'invoiceCreate', 'invoiceDelete', 'invoiceEmail', 'login', 'priceDelete', 'priceSet',
                'salesOrderCreate', 'salesOrderDelete',
                'salesOrderUpdate', 'salesTypeCreate', 'salesTypeUpdate', 'stockItemCreate', 'stockItemUpdate',
                'tokenRefresh', 'tokenRevoke',
            ],
            array_keys($schema->getMutationType()->getFields())
        );
        $this->assertNull($schema->getSubscriptionType());
    }

    public function testSalesTypesAreListedThroughMangoAndWrittenByCreateAndUpdate(): void
    {
        $sdl = SchemaPrinter::doPrint($this->schema());

        // The query argument's description is its queryable fields (issue #21).
        $this->assertMatchesRegularExpression(
            '/salesTypeList\(\s+"Fields for selector and sort: id, name, taxIncluded, factor, inactive\. [^"]*"'
            . '\s+query: MangoInput\s+\): \[SalesTypeType!\]!/',
            $sdl
        );
        $typePattern = '/type SalesTypeType \{\s+id: ID!\s+name: String\s+taxIncluded: Boolean'
            . '\s+factor: Float\s+inactive: Boolean\s+"[^"]*"\s+isBase: Boolean!\s+\}/';
        $this->assertMatchesRegularExpression($typePattern, $sdl);
        $inputPattern = '/input MangoInput \{\s+action: String\s+selector: String\s+limit: Int'
            . '\s+skip: Int\s+sort: \[String\]\s+\}/';
        $this->assertMatchesRegularExpression($inputPattern, $sdl);
        // Written by create and update; no delete, no upsert.
        $this->assertStringContainsString('salesTypeCreate(input: [SalesTypeCreateInput!]!)', $sdl);
        $this->assertStringContainsString('salesTypeUpdate(', $sdl);
        $this->assertStringNotContainsString('salesTypeDelete', $sdl);
        $this->assertStringNotContainsString('salesTypeUpsert', $sdl);
    }

    public function testStockItemInputsAreTheSpecsFields(): void
    {
        $schema = $this->schema();
        $create = $schema->getType('StockItemCreateInput');
        $update = $schema->getType('StockItemUpdateInput');

        $this->assertSame(
            ['stockId', 'description', 'categoryId', 'longDescription', 'inactive', 'taxTypeId', 'units', 'editable'],
            array_keys($create->getFields())
        );
        $this->assertSame(
            ['id', 'description', 'longDescription', 'categoryId', 'inactive', 'taxTypeId', 'units', 'editable'],
            array_keys($update->getFields())
        );
        foreach (['stockId' => 'String!', 'description' => 'String!', 'categoryId' => 'ID!'] as $name => $type) {
            $this->assertSame($type, (string) $create->getField($name)->getType(), "create $name");
        }
        $this->assertSame('ID!', (string) $update->getField('id')->getType());
        $this->assertSame('String', (string) $update->getField('description')->getType());
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
        $this->assertSame(24, $lists);
    }

    /**
     * Issue #21: the fields a list's query.selector and query.sort accept are in the
     * description of its query argument, and of its count's. Issue #27: total,
     * outstanding and voided are among them, worked out in SQL; the other computed
     * fields, lines and deliveryIds, are not: they are refused with BAD_INPUT.
     */
    public function testTheInvoiceListsQueryDescriptionNamesExactlyItsQueryableFields(): void
    {
        $fields = $this->schema()->getQueryType()->getFields();
        $expected = [
            'id', 'transType', 'version', 'customerId', 'branchId', 'date', 'dueDate', 'reference',
            'salesTypeId', 'orderId', 'amount', 'tax', 'freight', 'freightTax', 'discount', 'allocated',
            'prepaymentAmount', 'rate', 'shipperId', 'paymentTermsId', 'taxIncluded',
            'total', 'outstanding', 'voided',
        ];
        foreach (['invoiceList', 'invoiceCount'] as $name) {
            $description = (string) $fields[$name]->getArg('query')->description;
            $named = self::namedFields($description);
            $this->assertSame($expected, $named, $name);
            foreach (['lines', 'deliveryIds'] as $computed) {
                $this->assertNotContains($computed, $named, "$name: $computed");
            }
            $this->assertStringContainsString('Only these fields', $description, $name);
        }
    }

    /**
     * Issue #27: deliveryList's voided, and customerPaymentList's voided and
     * unallocated, are queryable; the fields read from other tables are not.
     */
    public function testDeliveriesAndPaymentsNameTheirComputedQueryableFields(): void
    {
        $fields = $this->schema()->getQueryType()->getFields();
        $cases = [
            'deliveryList' => [['voided'], ['lines']],
            'deliveryCount' => [['voided'], ['lines']],
            'customerPaymentList' => [['voided', 'unallocated'], ['allocations', 'bankAccountId', 'memo']],
            'customerPaymentCount' => [['voided', 'unallocated'], ['allocations', 'bankAccountId', 'memo']],
        ];
        foreach ($cases as $name => [$queryable, $refused]) {
            $named = self::namedFields((string) $fields[$name]->getArg('query')->description);
            $this->assertSame($queryable, array_slice($named, -count($queryable)), $name);
            foreach ($refused as $computed) {
                $this->assertNotContains($computed, $named, "$name: $computed");
            }
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
        $this->assertSame(24, $lists);
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
        // Anorm's Mango parser reads these words as operators, so a selector or sort
        // cannot name them: FaModelType::queryableFields() leaves them out.
        $operators = (new \ReflectionClassConstant(ModelType::class, 'OPERATOR_WORDS'))->getValue();

        return array_values(array_filter(
            array_keys((new $class(new UnconnectedPdo()))->mapper()->map),
            static function ($name) use ($operators): bool {
                return !in_array(strtolower((string) $name), $operators, true);
            }
        ));
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
