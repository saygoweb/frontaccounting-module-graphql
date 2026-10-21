<?php

namespace FA\GraphQL\Tests\Integration\Service;

use DI\Container;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\FaTestRows;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;

/**
 * A customer's text through the schema, created and listed as a client would
 * (saygoweb/frontaccounting-module-graphql#16): stored as customers.php stores what
 * is typed, read back as written. Rows carry this test's reference prefix and are
 * swept in tearDown.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CustomerTextTest extends FaTestCase
{
    private Container $container;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));
        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, 'apitest', 'customer-text-test', new \DateTimeImmutable('+5 minutes')));
        $this->prefix = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        FaTestRows::sweep($this->pdo(), $this->prefix);
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
     * Each text, as name, notes and the contact's email: created, then listed.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>} the
     *         create's read-back, the list's row, and the stored debtors_master row
     */
    private function roundTrip(string $name, string $notes): array
    {
        $fields = '{ id name ref notes }';
        $created = $this->gql(
            'mutation ($in: [CustomerCreateInput!]!) { customerCreate(input: $in) ' . $fields . ' }',
            ['in' => [[
                'name' => $name,
                'ref' => $this->prefix . 'c',
                'notes' => $notes,
                'salesTypeId' => '1',
                'paymentTermsId' => '3',
                'creditStatusId' => '1',
                'branch' => [
                    'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                    'locationId' => 'DEF', 'shipperId' => '1',
                ],
            ]]]
        )['customerCreate'][0];
        $listed = $this->gql(
            'query ($q: MangoInput) { customerList(query: $q) ' . $fields . ' }',
            ['q' => ['selector' => (string) json_encode(['id' => $created['id']])]]
        )['customerList'];
        $this->assertCount(1, $listed);
        $statement = $this->pdo()->prepare('SELECT * FROM 0_debtors_master WHERE debtor_no = ?');
        $statement->execute([(int) $created['id']]);

        return [$created, $listed[0], $statement->fetch(\PDO::FETCH_ASSOC)];
    }

    public function testACustomersTextIsStoredAsTheScreenStoresItAndReadBackAsWritten(): void
    {
        // Escaped, it must still fit cust_branch.br_name's 60 characters.
        $name = 'Clubs & Co <Trust> "Gold" \'24';
        [$created, $listed, $stored] = $this->roundTrip($name, 'Pays by "cheque" & cash');

        $this->assertSame($name, $created['name']);
        $this->assertSame($name, $listed['name']);
        $this->assertSame('Pays by "cheque" & cash', $listed['notes']);
        $this->assertSame('Clubs &amp; Co &lt;Trust&gt; &quot;Gold&quot; &#039;24', $stored['name']);
        $this->assertSame('Pays by &quot;cheque&quot; &amp; cash', $stored['notes']);
    }

    public function testTextThatSpellsAnEntityIsKeptAsAScreenKeepsIt(): void
    {
        // Typed into customers.php, "R&amp;D" arrives as "R&amp;amp;D" (html_cleanup)
        // and is stored so; the screen shows "R&amp;D" again.
        [$created, $listed, $stored] = $this->roundTrip('R&amp;D Ltd', 'Not &lt;b&gt;');

        $this->assertSame('R&amp;D Ltd', $created['name']);
        $this->assertSame('R&amp;D Ltd', $listed['name']);
        $this->assertSame('Not &lt;b&gt;', $listed['notes']);
        $this->assertSame('R&amp;amp;D Ltd', $stored['name']);
        $this->assertSame(htmlspecialchars('R&amp;D Ltd', ENT_QUOTES, 'UTF-8'), $stored['name']);
    }
}
