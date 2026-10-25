<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Fa\Service\ReportRunner;
use FA\GraphQL\Tests\Support\FaTestRows;
use PHPUnit\Framework\TestCase;

/**
 * The panel's pricing administration end to end (Items and prices spec §4.4, Release
 * B): with sgwpanel's machine token it makes four items, prices them on three lists
 * (Retail, Wholesale and a test-made "Reseller G"), quotes them, moves a customer
 * to the reseller list, and is refused a price list (no SA_SALESTYPES).
 *
 * The reseller list is made over HTTP as apitest, through FrontAccounting's own
 * add_sales_type. tearDown sweeps items, prices and lists by the test's prefix, and
 * customers, and deletes the machine token the test issued, pass or fail.
 */
class PricingAdminFlowTest extends TestCase
{
    use GraphQLClient;

    private string $prefix;

    private string $label;

    private string $token;

    private string $reseller;

    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $this->prefix = 'GQPA' . strtoupper(bin2hex(random_bytes(3)));
        $this->label = 'http-test-' . bin2hex(random_bytes(4));
        $this->token = $this->issue();

        $created = $this->gql(
            'mutation ($in: [SalesTypeCreateInput!]!) { salesTypeCreate(input: $in) { id } }',
            ['in' => [['name' => $this->prefix . ' Reseller G', 'taxIncluded' => false, 'factor' => 1]]],
            $this->login()['accessToken']
        );
        $this->assertArrayNotHasKey('errors', $created['body'], $created['raw']);
        $this->reseller = $created['body']['data']['salesTypeCreate'][0]['id'];
    }

    protected function tearDown(): void
    {
        if (!isset($this->label)) {
            return;
        }
        $pdo = $this->pdo();
        FaTestRows::sweepItems($pdo, $this->prefix, $this->table(''));
        FaTestRows::sweep($pdo, $this->prefix, $this->table(''));
        $pdo->prepare('DELETE FROM ' . $this->table('graphql_machine_token') . ' WHERE label LIKE ?')
            ->execute([$this->label . '%']);
    }

    public function testThePanelsPricingFlow(): void
    {
        $me = $this->ok('{ me { areas } }');
        $this->assertContains('SA_ITEM', $me['me']['areas']);
        $this->assertContains('SA_SALESPRICE', $me['me']['areas']);

        $codes = [];
        $items = [];
        foreach (['NZ', 'COM', 'ORG', 'NET'] as $suffix) {
            $codes[$suffix] = $this->prefix . '-' . $suffix;
            $items[] = [
                'stockId' => $codes[$suffix],
                'description' => "Domain .$suffix",
                'categoryId' => '4',
                'units' => 'each',
                'editable' => true,
            ];
        }
        $created = $this->ok(
            'mutation ($in: [StockItemCreateInput!]!) { stockItemCreate(input: $in) { id editable } }',
            ['in' => $items]
        )['stockItemCreate'];
        $this->assertCount(4, $created);
        foreach ($created as $row) {
            $this->assertTrue($row['editable']);
        }

        $prices = [];
        foreach ($codes as $suffix => $code) {
            $prices[] = ['stockId' => $code, 'salesTypeId' => '1', 'currency' => 'USD', 'price' => 50];
            $prices[] = ['stockId' => $code, 'salesTypeId' => '2', 'currency' => 'USD', 'price' => 45];
            $prices[] = [
                'stockId' => $code,
                'salesTypeId' => $this->reseller,
                'currency' => 'USD',
                'price' => $suffix === 'NZ' ? 35 : 30,
            ];
        }
        $set = $this->ok(
            'mutation ($in: [PriceSetInput!]!) { priceSet(input: $in) { id stockId salesTypeId currency price } }',
            ['in' => $prices]
        )['priceSet'];
        $this->assertCount(12, $set);

        $listed = $this->ok(
            'query ($q: MangoInput) { priceList(query: $q) { stockId salesTypeId price } }',
            ['q' => ['selector' => json_encode(['stockId' => $codes['NZ']])]]
        )['priceList'];
        $this->assertCount(3, $listed);

        $quote = 'query ($s: String!, $c: ID, $t: ID) '
            . '{ priceQuote(stockId: $s, customerId: $c, salesTypeId: $t) { price currency } }';
        $wholesale = $this->ok($quote, ['s' => $codes['NZ'], 't' => '2'])['priceQuote'];
        $this->assertEqualsWithDelta(45.0, $wholesale['price'], 0.001, "list 2's own price, not 50 x 0.7");
        $viaReseller = $this->ok($quote, ['s' => $codes['NZ'], 't' => $this->reseller])['priceQuote'];
        $this->assertEqualsWithDelta(35.0, $viaReseller['price'], 0.001);

        $customer = $this->ok(
            'mutation ($in: [CustomerCreateInput!]!) { customerCreate(input: $in) { id } }',
            ['in' => [[
                'name' => "Pricing customer {$this->prefix}",
                'ref' => $this->prefix . 'c',
                'address' => "1 Pricing Street\nHosting Town",
                'salesTypeId' => '1',
                'paymentTermsId' => '1',
                'creditStatusId' => '1',
                'branch' => [
                    'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                    'locationId' => 'DEF', 'shipperId' => '1',
                ],
                'contact' => ['email' => strtolower($this->prefix) . '@example.com'],
            ]]]
        )['customerCreate'][0];
        $this->ok(
            'mutation ($in: [CustomerUpdateInput!]!) { customerUpdate(input: $in) { id salesTypeId } }',
            ['in' => [['id' => $customer['id'], 'salesTypeId' => $this->reseller]]]
        );
        $forCustomer = $this->ok($quote, ['s' => $codes['COM'], 'c' => $customer['id']])['priceQuote'];
        $this->assertEqualsWithDelta(30.0, $forCustomer['price'], 0.001);

        $denied = $this->gql(
            'mutation ($in: [SalesTypeCreateInput!]!) { salesTypeCreate(input: $in) { id } }',
            ['in' => [['name' => $this->prefix . ' Denied']]],
            $this->token
        );
        $this->assertSame('FORBIDDEN', $denied['body']['errors'][0]['extensions']['code'] ?? null, $denied['raw']);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the response's data
     */
    private function ok(string $query, array $variables = []): array
    {
        $response = $this->gql($query, $variables, $this->token);
        $this->assertSame(200, $response['status'], $response['raw']);
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);

        return $response['body']['data'];
    }

    private function issue(): string
    {
        $run = (new ReportRunner())->run([
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/fa-token',
            'issue', '--company', '0', '--user', 'sgwpanel', '--days', '1', '--label', $this->label,
        ], 30);
        $this->assertSame(0, $run->exitCode, $run->stderr . $run->stdout);

        return trim($run->stdout);
    }

    private function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO(
                'mysql:host=' . getenv('FA_DB_HOST') . ';dbname=' . getenv('FA_DB_NAME'),
                (string) getenv('FA_DB_USER'),
                (string) getenv('FA_DB_PASSWORD')
            );
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    private function table(string $name): string
    {
        return getenv('FA_DB_PREFIX') . $name;
    }
}
