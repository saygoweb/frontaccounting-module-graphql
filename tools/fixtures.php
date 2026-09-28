#!/usr/bin/env php
<?php

/*
 * Creates a development environment's example hosting-billing documents THROUGH
 * THE GRAPHQL API itself, signed in as apitest (tests/data/seed.sql) — the same way
 * the panel app would. Run by tools/dev-fixtures.sh in a development environment
 * (docker/ci/plugin-dev.sh in cambell-prince/frontaccounting), after
 * tests/data/dev-fixtures.sql has loaded the HDOM/HGEN1 items and their prices.
 *
 * What it creates:
 *   - a customer, "Example Hosting Reseller Ltd" (ref EXAMPLE-RESELLER), USD, with
 *     its default branch and a billing@example.com contact;
 *   - two yearly recurring sales orders shaped like the live data, one per
 *     "customer" of the reseller (customerRef sgw-hosting-1001 / -1002);
 *   - the first order invoiced in one step (an open delivery-and-invoice, so an
 *     open order and a settled invoice both exist as examples);
 *   - a customer payment for that invoice's full total, allocated to it, on the
 *     home-currency bank account.
 *
 * Idempotent: if a customer with ref EXAMPLE-RESELLER already exists, nothing here
 * is created again — its customer, branch, orders, invoice and payment (if any) are
 * read back and reported instead.
 *
 * Usage: php tools/fixtures.php [<graphql-url>]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

const CUSTOMER_REF = 'EXAMPLE-RESELLER';

const ORDER_FIELDS = '{ id version customerId branchId customerRef orderDate '
    . 'lines { id stockId description quantity unitPrice } '
    . 'recurring { start end repeats every monthDay } }';

const INVOICE_FIELDS = '{ id reference total outstanding voided }';

const PAYMENT_FIELDS = '{ id amount unallocated allocations { toId amount } }';

/**
 * @param array<string, mixed> $variables
 * @return array<string, mixed>
 */
function gql(string $url, string $query, array $variables, ?string $token = null): array
{
    $headers = "Content-Type: application/json\r\n";
    if ($token !== null) {
        $headers .= "Authorization: Bearer $token\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => $headers,
        'content' => (string) json_encode(['query' => $query, 'variables' => $variables]),
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);
    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        fwrite(STDERR, "fixtures: no response from $url\n");
        exit(1);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "fixtures: not JSON from $url: " . substr($raw, 0, 300) . "\n");
        exit(1);
    }

    return $decoded;
}

/**
 * @param array<string, mixed> $variables
 * @return array<string, mixed> the response's data
 */
function ok(string $url, string $token, string $query, array $variables): array
{
    $response = gql($url, $query, $variables, $token);
    if (isset($response['errors'])) {
        fwrite(STDERR, "fixtures: GraphQL error: " . json_encode($response['errors']) . "\n");
        exit(1);
    }

    return $response['data'];
}

/**
 * The customer, its branch, orders, invoice and payment, as they stand right now —
 * whether just created or found already there. Printed for whoever ran the command.
 */
function report(string $url, string $token, string $customerId): void
{
    $customer = ok(
        $url,
        $token,
        'query($q: MangoInput) { customerList(query: $q) { id name branches { id } contacts { id email } } }',
        ['q' => ['selector' => json_encode(['id' => (int) $customerId])]]
    )['customerList'][0] ?? null;
    if ($customer === null) {
        fwrite(STDERR, "fixtures: customer $customerId vanished while reporting\n");
        exit(1);
    }

    $orders = ok(
        $url,
        $token,
        'query($q: MangoInput) { salesOrderList(query: $q) ' . ORDER_FIELDS . ' }',
        ['q' => ['selector' => json_encode(['customerId' => (int) $customerId])]]
    )['salesOrderList'];

    $invoices = ok(
        $url,
        $token,
        'query($q: MangoInput) { invoiceList(query: $q) ' . INVOICE_FIELDS . ' }',
        ['q' => ['selector' => json_encode(['customerId' => (int) $customerId])]]
    )['invoiceList'];

    $payments = ok(
        $url,
        $token,
        'query($q: MangoInput) { customerPaymentList(query: $q) ' . PAYMENT_FIELDS . ' }',
        ['q' => ['selector' => json_encode(['customerId' => (int) $customerId])]]
    )['customerPaymentList'];

    fwrite(STDOUT, "\n" . json_encode([
        'customer' => $customer,
        'orders' => $orders,
        'invoices' => $invoices,
        'payments' => $payments,
    ], JSON_PRETTY_PRINT) . "\n");
}

$url = $argv[1] ?? (getenv('FA_GRAPHQL_URL') ?: rtrim((string) (getenv('FA_URL') ?: 'http://localhost'), '/') . '/modules/graphql/');

$loginResponse = gql(
    $url,
    'mutation($u: String!, $p: String!) { login(user: $u, password: $p) { accessToken } }',
    ['u' => 'apitest', 'p' => 'password']
);
$token = $loginResponse['data']['login']['accessToken'] ?? null;
if ($token === null) {
    fwrite(STDERR, "fixtures: could not log in as apitest: " . json_encode($loginResponse) . "\n");
    exit(1);
}

$existing = ok(
    $url,
    $token,
    'query($q: MangoInput) { customerList(query: $q) { id } }',
    ['q' => ['selector' => json_encode(['ref' => CUSTOMER_REF])]]
)['customerList'];

if ($existing !== []) {
    fwrite(STDOUT, 'fixtures: customer ref ' . CUSTOMER_REF . ' already exists (id='
        . $existing[0]['id'] . ") — skipping the API part.\n");
    report($url, $token, (string) $existing[0]['id']);
    exit(0);
}

// The demo's standard 30-day / following-month payment term: dayInFollowingMonth 30
// (id 4, "Cash Only", is not it — checked against paymentTermsList rather than assumed).
$terms = ok($url, $token, '{ paymentTermsList { id dayInFollowingMonth inactive } }', [])['paymentTermsList'];
$termId = null;
foreach ($terms as $term) {
    if (!$term['inactive'] && (int) $term['dayInFollowingMonth'] === 30) {
        $termId = $term['id'];
        break;
    }
}
if ($termId === null) {
    fwrite(STDERR, "fixtures: no 30-day/following-month payment term found: " . json_encode($terms) . "\n");
    exit(1);
}

$customer = ok(
    $url,
    $token,
    'mutation($in: [CustomerCreateInput!]!) { customerCreate(input: $in) '
        . '{ id name branches { id } contacts { id email } } }',
    ['in' => [[
        'name' => 'Example Hosting Reseller Ltd',
        'ref' => CUSTOMER_REF,
        'address' => "1 Reseller Street\nHosting City",
        'currencyId' => 'USD',
        'salesTypeId' => '1',
        'creditStatusId' => '1',
        'paymentTermsId' => $termId,
        'branch' => [
            'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
            'locationId' => 'DEF', 'shipperId' => '1',
        ],
        'contact' => ['email' => 'billing@example.com'],
    ]]]
)['customerCreate'][0];
$customerId = $customer['id'];
$branchId = $customer['branches'][0]['id'];

$today = date('Y-m-d');
$year = date('Y');

$order1 = ok(
    $url,
    $token,
    'mutation($in: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $in) ' . ORDER_FIELDS . ' }',
    ['in' => [[
        'customerId' => $customerId,
        'branchId' => $branchId,
        'customerRef' => 'sgw-hosting-1001',
        'orderDate' => $today,
        'lines' => [
            ['stockId' => 'HGEN1', 'quantity' => 1, 'description' => 'Hosting - example-one.test'],
            ['stockId' => 'HDOM', 'quantity' => 1, 'description' => 'Domain Registration - example-one.test'],
        ],
        'recurring' => ['start' => "$year-01-01", 'repeats' => 'YEAR', 'every' => 1, 'monthDay' => '01-01'],
    ]]]
)['salesOrderCreate'][0];

$order2 = ok(
    $url,
    $token,
    'mutation($in: [SalesOrderCreateInput!]!) { salesOrderCreate(input: $in) ' . ORDER_FIELDS . ' }',
    ['in' => [[
        'customerId' => $customerId,
        'branchId' => $branchId,
        'customerRef' => 'sgw-hosting-1002',
        'orderDate' => $today,
        'lines' => [
            ['stockId' => 'HGEN1', 'quantity' => 1, 'description' => 'Hosting - example-two.test'],
            ['stockId' => 'HDOM', 'quantity' => 2, 'description' => 'Domain Registration - example-two.test'],
        ],
        'recurring' => ['start' => "$year-06-01", 'repeats' => 'YEAR', 'every' => 1, 'monthDay' => '06-01'],
    ]]]
)['salesOrderCreate'][0];

// Invoice the first order in one step: FrontAccounting delivers what remains, then
// invoices it. The second order is left open, undelivered — the open example.
$invoice = ok(
    $url,
    $token,
    'mutation($in: [InvoiceCreateInput!]!) { invoiceCreate(input: $in) ' . INVOICE_FIELDS . ' }',
    ['in' => [['orderId' => $order1['id'], 'orderVersion' => $order1['version'], 'date' => $today]]]
)['invoiceCreate'][0];

// Pay it in full, allocated to the invoice — the settled example.
$payment = ok(
    $url,
    $token,
    'mutation($in: [CustomerPaymentCreateInput!]!) { customerPaymentCreate(input: $in) ' . PAYMENT_FIELDS . ' }',
    ['in' => [[
        'customerId' => $customerId,
        'branchId' => $branchId,
        'bankAccountId' => '1',
        'date' => $today,
        'amount' => $invoice['total'],
        'allocations' => [['invoiceId' => $invoice['id'], 'amount' => $invoice['total']]],
    ]]]
)['customerPaymentCreate'][0];

fwrite(STDOUT, "fixtures: created customer $customerId (branch $branchId), orders "
    . "{$order1['id']} and {$order2['id']}, invoice {$invoice['id']}, payment {$payment['id']}.\n");
report($url, $token, (string) $customerId);
