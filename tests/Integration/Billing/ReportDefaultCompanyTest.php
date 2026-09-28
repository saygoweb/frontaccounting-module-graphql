<?php

namespace FA\GraphQL\Tests\Integration\Billing;

use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\Service\CustomerService;
use FA\GraphQL\Fa\Service\InvoiceMailer;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Tests\Support\FaTestRows;
use FA\GraphQL\Tests\Support\MailCatcher;
use FA\GraphQL\Tests\Support\ReportFiles;

/**
 * Release 4 spec §4.3: the report child logs in for its target company even when
 * FrontAccounting's default company does not have this module active. Runs only
 * under tools/ci.sh's default-company step, which makes company 1 the default
 * with graphql inactive there and removes it afterwards; skipped otherwise.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReportDefaultCompanyTest extends InvoiceTestCase
{
    /** @var string[] */
    private array $mailBefore = [];

    /** @var string[] */
    private array $pdfBefore = [];

    private string $prefix = '';

    protected function setUp(): void
    {
        parent::setUp();
        if ((int) ($GLOBALS['def_coy'] ?? 0) !== 1 || !isset($GLOBALS['db_connections'][1])) {
            $this->markTestSkipped('Needs company 1 as the default: run tools/ci.sh.');
        }
        if (!MailCatcher::available()) {
            $this->markTestSkipped('The CI image mail catcher is not installed (/var/mail-catcher).');
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

    public function testAnInvoiceOfCompany0IsEmailedWhileCompany1IsTheDefault(): void
    {
        $invoiceId = $this->invoiceForANewCustomer('gqlt-defcoy@example.com');

        $results = $this->container->get(InvoiceMailer::class)->send([$invoiceId]);

        $this->assertTrue($results[0]['sent'], implode("\n", $results[0]['messages']));
        $this->assertNotSame([InvoiceMailer::DID_NOT_RUN], $results[0]['messages']);
        $this->assertCount(1, MailCatcher::newSince($this->mailBefore));
    }

    /**
     * As InvoiceEmailTest's: a new customer with a `general` CRM contact of $email, an
     * order for it on credit terms, invoiced in one step.
     */
    private function invoiceForANewCustomer(string $email): int
    {
        $input = [
            'name' => 'GraphQL Default Company Customer',
            'ref' => $this->prefix . 'c',
            'salesTypeId' => '1',
            'paymentTermsId' => '3',
            'creditStatusId' => '1',
            'branch' => [
                'salesmanId' => '1', 'salesAreaId' => '1', 'taxGroupId' => '1',
                'locationId' => 'DEF', 'shipperId' => '1',
            ],
            'contact' => ['phone' => '555-0100', 'email' => $email],
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
