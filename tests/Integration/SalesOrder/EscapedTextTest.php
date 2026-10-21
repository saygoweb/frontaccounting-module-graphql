<?php

namespace FA\GraphQL\Tests\Integration\SalesOrder;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;

/**
 * FrontAccounting stores text HTML-escaped (& as &amp;, and so on: db_escape() and
 * html_cleanup()). The API reads and writes the text itself
 * (saygoweb/frontaccounting-module-graphql#16), through the schema as a client would.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EscapedTextTest extends SalesOrderTestCase
{
    private const TEXT = 'Hosting - Clubs & Charities <b>"Gold"</b> \'24';

    private const ORDER_FIELDS = '{ id comments deliverTo lines { description } }';

    /** @var mixed 101's editable flag before the test: a line takes a description only when it is set */
    private $editable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editable = $this->pdo()
            ->query("SELECT editable FROM 0_stock_master WHERE stock_id = '101'")
            ->fetchColumn();
        $this->pdo()->exec("UPDATE 0_stock_master SET editable = 1 WHERE stock_id = '101'");
    }

    protected function tearDown(): void
    {
        $this->pdo()->prepare("UPDATE 0_stock_master SET editable = ? WHERE stock_id = '101'")
            ->execute([$this->editable]);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function gql(string $query, array $variables = []): array
    {
        $result = GraphQL::executeQuery(
            $this->container->get(Schema::class),
            $query,
            null,
            $this->container,
            $variables
        )->toArray();
        $this->assertArrayNotHasKey('errors', $result, (string) json_encode($result));

        return $result['data'];
    }

    /**
     * @param array<string, mixed> $selector
     * @return array<int, array<string, mixed>>
     */
    private function listed(array $selector): array
    {
        return $this->gql(
            'query ($q: MangoInput) { salesOrderList(query: $q) ' . self::ORDER_FIELDS . ' }',
            ['q' => ['selector' => (string) json_encode($selector)]]
        )['salesOrderList'];
    }

    private function createThroughTheApi(string $text): int
    {
        $created = $this->gql(
            'mutation ($in: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $in) ' . self::ORDER_FIELDS . ' }',
            ['in' => [[
                'customerId' => '1',
                'branchId' => '1',
                'orderDate' => $this->today(),
                'paymentTermsId' => '3',
                'deliverTo' => $text,
                'deliveryAddress' => '1 Test Street',
                'comments' => $text,
                'lines' => [['stockId' => '101', 'quantity' => 2.0, 'description' => $text]],
            ]]]
        )['salesOrderCreate'];
        $this->assertCount(1, $created);
        $id = (int) $created[0]['id'];
        $this->track($id);

        $this->assertSame($text, $created[0]['comments'], 'the create\'s read-back');
        $this->assertSame($text, $created[0]['deliverTo']);
        $this->assertSame($text, $created[0]['lines'][0]['description']);

        return $id;
    }

    public function testTextWrittenThroughTheApiIsStoredAsAScreenStoresItAndReadBackAsWritten(): void
    {
        $id = $this->createThroughTheApi(self::TEXT);

        // What sales_order_entry.php stores for the same typed text: html_cleanup()
        // escapes the POST, db_escape() keeps it escaped.
        $stored = htmlspecialchars(self::TEXT, ENT_QUOTES, 'UTF-8');
        $this->assertSame('Hosting - Clubs &amp; Charities &lt;b&gt;&quot;Gold&quot;&lt;/b&gt; &#039;24', $stored);
        $this->assertSame($stored, $this->orderRow($id)['comments']);
        $this->assertSame($stored, $this->orderRow($id)['deliver_to']);
        $this->assertSame($stored, $this->lineRows($id)[0]['description']);

        $listed = $this->listed(['id' => (string) $id]);
        $this->assertCount(1, $listed);
        $this->assertSame(self::TEXT, $listed[0]['comments']);
        $this->assertSame(self::TEXT, $listed[0]['deliverTo']);
        $this->assertSame(self::TEXT, $listed[0]['lines'][0]['description']);
    }

    public function testASalesDocumentCollapsesTextThatSpellsAnEntityAsItsScreenDoes(): void
    {
        // Cart::write() html_entity_decode()s the comments and line descriptions
        // before db_escape() decodes them again, on sales_order_entry.php's path too:
        // "R&amp;D" typed there is stored as "R&amp;D" and shown as "R&D".
        $created = $this->gql(
            'mutation ($in: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $in) ' . self::ORDER_FIELDS . ' }',
            ['in' => [[
                'customerId' => '1',
                'branchId' => '1',
                'orderDate' => $this->today(),
                'paymentTermsId' => '3',
                'deliverTo' => 'R&amp;D',
                'deliveryAddress' => '1 Test Street',
                'comments' => 'R&amp;D',
                'lines' => [['stockId' => '101', 'quantity' => 2.0, 'description' => 'R&amp;D']],
            ]]]
        )['salesOrderCreate'][0];
        $this->track((int) $created['id']);

        $this->assertSame('R&D', $created['comments']);
        $this->assertSame('R&D', $created['lines'][0]['description']);
        $this->assertSame('R&amp;D', $created['deliverTo'], 'a header field Cart::write() does not decode is kept');
    }

    public function testTextAScreenStoredIsReadAsTheText(): void
    {
        $id = $this->createOrder();
        // As a screen stores "R&D <Ltd> "x" 'y'".
        $this->pdo()->prepare('UPDATE 0_sales_orders SET comments = ? WHERE order_no = ? AND trans_type = 30')
            ->execute(['R&amp;D &lt;Ltd&gt; &quot;x&quot; &#039;y&#039;', $id]);
        $this->pdo()->prepare('UPDATE 0_sales_order_details SET description = ? WHERE order_no = ? AND trans_type = 30')
            ->execute(['Clubs &amp; Charities', $id]);

        $listed = $this->listed(['id' => (string) $id]);
        $this->assertSame('R&D <Ltd> "x" \'y\'', $listed[0]['comments']);
        $this->assertSame('Clubs & Charities', $listed[0]['lines'][0]['description']);
    }

    public function testASelectorMatchesTheTextAsTheClientReadsIt(): void
    {
        $id = $this->createThroughTheApi(self::TEXT);

        $ids = function (array $selector) use ($id): array {
            return array_values(array_filter(array_map(function (array $row): int {
                return (int) $row['id'];
            }, $this->listed($selector)), function (int $found) use ($id): bool {
                return $found === $id;
            }));
        };
        $this->assertSame([$id], $ids(['comments' => self::TEXT]));
        $this->assertSame([$id], $ids(['comments' => ['$in' => ['nothing', self::TEXT]]]));
        $this->assertSame(
            [$id],
            $ids(['$and' => [['id' => (string) $id], ['comments' => ['$regex' => 'Clubs & Char']]]])
        );
        $this->assertSame([$id], $ids(['comments' => ['$beginsWith' => 'Hosting - Clubs & ']]));
    }
}
