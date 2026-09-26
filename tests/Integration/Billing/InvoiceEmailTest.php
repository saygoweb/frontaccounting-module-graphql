<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\InvoiceMailer;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Tests\Support\MailCatcher;
use FA\GraphQL\Tests\Support\ReportFiles;

/**
 * invoiceEmail (Release 3 spec section 6): FrontAccounting's rep107 in a real CLI
 * child process (bin/fa-report), its mail caught by the stack's catcher. Signed in
 * as apitest, with the billing cleanup, by InvoiceTestCase; the customers each test
 * makes are swept by their reference prefix, and only the mail and report PDFs a
 * test caused are deleted.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class InvoiceEmailTest extends InvoiceTestCase
{
    /** @var string[] */
    private array $mailBefore = [];

    /** @var string[] */
    private array $pdfBefore = [];

    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (!MailCatcher::available()) {
            $this->markTestSkipped('The stack mail catcher is not installed (docker/fa-graphql up --build).');
        }
        $this->mailBefore = MailCatcher::files();
        $this->pdfBefore = ReportFiles::files(Bootstrap::defaultRoot());
        $this->prefix = FaTestRows::prefix();
    }

    protected function tearDown(): void
    {
        MailCatcher::delete(MailCatcher::newSince($this->mailBefore));
        ReportFiles::deleteNew(Bootstrap::defaultRoot(), $this->pdfBefore);
        parent::tearDown();
        if ($this->prefix !== '') {
            FaTestRows::sweep($this->pdo(), $this->prefix);
        }
    }

    private function mailer(): InvoiceMailer
    {
        return $this->container->get(InvoiceMailer::class);
    }

    public function testAnInvoiceIsEmailedToTheCustomersContact(): void
    {
        $invoiceId = $this->invoiceForANewCustomer('gqlt-mail@example.com');
        $failLog = Bootstrap::defaultRoot() . '/tmp/faillog.php';
        $failLogBefore = is_file($failLog) ? file_get_contents($failLog) : null;

        $results = $this->mailer()->send([$invoiceId]);

        $this->assertCount(1, $results);
        $this->assertSame($invoiceId, $results[0]['id']);
        $this->assertTrue($results[0]['sent'], implode("\n", $results[0]['messages']));
        $this->assertSame('gqlt-mail@example.com', $results[0]['recipient']);

        $new = MailCatcher::newSince($this->mailBefore);
        $this->assertCount(1, $new);
        $eml = (string) file_get_contents($new[0]);
        $this->assertMatchesRegularExpression('/^To: .*gqlt-mail@example\.com/m', $eml);
        $this->assertStringContainsString('Content-Disposition: attachment', $eml);
        $this->assertMatchesRegularExpression('/filename="[^"]*\.pdf"/', $eml);

        $this->assertSame(
            $failLogBefore,
            is_file($failLog) ? file_get_contents($failLog) : null,
            'the child logs in through hook_authenticate and must not reset the web UI throttle'
        );
    }

    public function testACustomerWithoutAnEmailContactIsNotSent(): void
    {
        $invoiceId = $this->invoiceForANewCustomer(null);

        $results = $this->mailer()->send([$invoiceId]);

        $this->assertFalse($results[0]['sent']);
        $this->assertNull($results[0]['recipient']);
        $this->assertStringContainsString('no email contact', implode("\n", $results[0]['messages']));
        $this->assertCount(0, MailCatcher::newSince($this->mailBefore));
    }

    public function testAnUnknownInvoiceIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->mailer()->send([999999]);
    }

    public function testAVoidedInvoiceIsRefused(): void
    {
        $invoiceId = $this->invoiceForANewCustomer('gqlt-void@example.com');
        $this->voidInvoice($invoiceId);

        try {
            $this->mailer()->send([$invoiceId]);
            $this->fail('a voided invoice was emailed');
        } catch (FaRejected $e) {
            $this->assertStringContainsString('voided', $e->getMessage());
        }
        $this->assertCount(0, MailCatcher::newSince($this->mailBefore));
    }

    public function testItRefusesToRunInsideATransaction(): void
    {
        $invoiceId = $this->invoiceForANewCustomer('gqlt-tx@example.com');
        begin_transaction();
        try {
            $this->expectException(\LogicException::class);
            $this->mailer()->send([$invoiceId]);
        } finally {
            cancel_transaction();
        }
    }

    /**
     * A new customer (its default branch, and one CRM person linked to both as
     * `general`, with $email when given), an order for it on credit terms, and that
     * order invoiced in one step. rep107 finds the person through
     * get_branch_contacts($branch, 'invoice', ...), which falls back to `general`.
     */
    private function invoiceForANewCustomer(?string $email): int
    {
        $contact = ['phone' => '555-0100'];
        if ($email !== null) {
            $contact['email'] = $email;
        }
        $input = [
            'name' => 'GraphQL Mail Customer',
            'ref' => $this->prefix . 'c',
            'salesTypeId' => '1',
            'paymentTermsId' => '3',
            'creditStatusId' => '1',
            'branch' => [
                'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                'locationId' => 'DEF', 'shipperId' => '1',
            ],
            'contact' => $contact,
        ];
        $customerId = ServiceCall::run(function () use ($input): int {
            return $this->container->get(CustomerService::class)->create($input);
        });
        $statement = $this->pdo()->prepare('SELECT branch_code FROM 0_cust_branch WHERE debtor_no = ?');
        $statement->execute([$customerId]);
        $branchId = (int) $statement->fetchColumn();

        $orderNo = $this->createOrder(['customerId' => $customerId, 'branchId' => $branchId]);

        return $this->invoice([
            'orderId' => $orderNo,
            'orderVersion' => (int) $this->orderRow($orderNo)['version'],
            'date' => new \DateTimeImmutable($this->today()),
        ]);
    }
}
