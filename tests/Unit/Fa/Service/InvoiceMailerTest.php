<?php

namespace FA\GraphQL\Tests\Unit\Fa\Service;

use FA\GraphQL\Fa\Service\InvoiceMailer;
use FA\GraphQL\Fa\Service\ReportRun;
use PHPUnit\Framework\TestCase;

class InvoiceMailerTest extends TestCase
{
    private function run1(string $stdout, bool $timedOut = false, ?int $exit = 0): ReportRun
    {
        $run = new ReportRun();
        $run->stdout = $stdout;
        $run->stderr = '';
        $run->exitCode = $exit;
        $run->timedOut = $timedOut;
        return $run;
    }

    private function line(array $messages): string
    {
        return InvoiceMailer::RESULT_PREFIX . json_encode(['messages' => $messages]) . "\n";
    }

    public function testASentNotificationIsSentWithItsRecipient(): void
    {
        $r = InvoiceMailer::interpret($this->run1($this->line([
            ['level' => 'notice', 'text' => 'INVOICE 12 has been sent by email to destination. Email: a.b@example.com'],
        ])), 60);
        $this->assertTrue($r['sent']);
        $this->assertSame('a.b@example.com', $r['recipient']);
        $this->assertSame(['INVOICE 12 has been sent by email to destination. Email: a.b@example.com'], $r['messages']);
    }

    public function testAWarningMeansNotSent(): void
    {
        $r = InvoiceMailer::interpret($this->run1($this->line([
            ['level' => 'warning', 'text' => "You have no email contact defined for this type of document for 'Acme'."],
        ])), 60);
        $this->assertFalse($r['sent']);
        $this->assertNull($r['recipient']);
        $this->assertStringContainsString('no email contact', $r['messages'][0]);
    }

    public function testAnErrorBesideANotificationMeansNotSent(): void
    {
        $r = InvoiceMailer::interpret($this->run1($this->line([
            ['level' => 'notice', 'text' => 'sent. Email: x@example.com'],
            ['level' => 'error', 'text' => 'DATABASE ERROR'],
        ])), 60);
        $this->assertFalse($r['sent']);
    }

    public function testGarbageBeforeTheResultIsIgnored(): void
    {
        $out = "<html><body>Restricted</body></html>\nWarning: something\n"
            . $this->line([['level' => 'notice', 'text' => 'sent. Email: x@example.com']])
            . "trailing output from end_flush\n";
        $r = InvoiceMailer::interpret($this->run1($out), 60);
        $this->assertTrue($r['sent']);
        $this->assertSame('x@example.com', $r['recipient']);
    }

    public function testTheLastResultLineWins(): void
    {
        $out = $this->line([['level' => 'warning', 'text' => 'first']])
            . $this->line([['level' => 'notice', 'text' => 'sent. Email: y@example.com']]);
        $r = InvoiceMailer::interpret($this->run1($out), 60);
        $this->assertTrue($r['sent']);
        $this->assertSame('y@example.com', $r['recipient']);
    }

    public function testNoResultLineMeansNotSent(): void
    {
        $r = InvoiceMailer::interpret($this->run1("<html>login page</html>", false, 0), 60);
        $this->assertFalse($r['sent']);
        $this->assertSame(['The report process ended without a result.'], $r['messages']);
    }

    public function testATimedOutChildIsReported(): void
    {
        $r = InvoiceMailer::interpret($this->run1('', true, null), 60);
        $this->assertFalse($r['sent']);
        $this->assertSame(
            ['The report process ended without a result.', 'It was stopped after 60 seconds.'],
            $r['messages']
        );
    }

    public function testAnUnreadableResultLineMeansNotSent(): void
    {
        $r = InvoiceMailer::interpret($this->run1(InvoiceMailer::RESULT_PREFIX . "{not json\n"), 60);
        $this->assertFalse($r['sent']);
        $this->assertSame(['The report process ended without a result.'], $r['messages']);
    }

    public function testAResultWithNoMessagesSaysTheReportDidNotRun(): void
    {
        // Final review M-1: session.inc refuses a login (an unknown user or company, an
        // inactive user, graphql not active in the default company) without a message.
        $r = InvoiceMailer::interpret($this->run1($this->line([])), 60);
        $this->assertFalse($r['sent']);
        $this->assertNull($r['recipient']);
        $this->assertSame([InvoiceMailer::DID_NOT_RUN], $r['messages']);
        $this->assertStringContainsString('refused the login', InvoiceMailer::DID_NOT_RUN);
    }

    public function testTheArgvIsAnArrayWithTheCompanyLoginAndInvoice(): void
    {
        $this->assertSame(
            [PHP_BINARY, '/m/bin/fa-report', '107', '0', 'apitest', '12', 'email'],
            InvoiceMailer::argv('/m/bin/fa-report', 0, 'apitest', 12)
        );
    }

    public function testUnderAWebSapiTheCliBinaryBesideThisPhpIsUsed(): void
    {
        // mod_php's PHP_BINARY is empty or the web server; the child needs PHP's CLI.
        $dir = sys_get_temp_dir() . '/fa-graphql-php-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->assertSame('/usr/bin/php7.4', InvoiceMailer::phpBinary('cli', '/usr/bin/php7.4', $dir));
            $this->assertSame('php', InvoiceMailer::phpBinary('apache2handler', '', $dir), 'found on PATH');
            touch($dir . '/php');
            chmod($dir . '/php', 0755);
            $this->assertSame($dir . '/php', InvoiceMailer::phpBinary('apache2handler', '/usr/sbin/apache2', $dir));
            $versioned = $dir . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            touch($versioned);
            chmod($versioned, 0755);
            $this->assertSame($versioned, InvoiceMailer::phpBinary('fpm-fcgi', '/usr/sbin/php-fpm', $dir));
        } finally {
            @unlink($dir . '/php');
            @unlink($dir . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
            rmdir($dir);
        }
    }
}
