<?php

namespace FA\GraphQL\Fa\Service;

use FA\GraphQL\Error\FaRejected;
use FA\GraphQL\Error\NotFound;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;

/**
 * Emails invoices through FrontAccounting's own invoice report (rep107), which can
 * only run in a process of its own: it includes session.inc (Release 3 spec §6).
 * One child per invoice, after the caller's writes have committed, stopped after
 * the timeout. The child's result line is the only thing read from its output.
 */
class InvoiceMailer
{
    public const RESULT_PREFIX = 'FA_REPORT_RESULT ';
    private const NO_RESULT = 'The report process ended without a result.';

    private ReportRunner $runner;
    private FaSession $session;
    private string $script;
    private int $timeoutSeconds;

    public function __construct(
        ReportRunner $runner,
        FaSession $session,
        ?string $script = null,
        int $timeoutSeconds = 60
    ) {
        $this->runner = $runner;
        $this->session = $session;
        $this->script = $script ?? dirname(__DIR__, 3) . '/bin/fa-report';
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, array{id: int, sent: bool, recipient: ?string, messages: string[]}>
     */
    public function send(array $ids): array
    {
        if (($GLOBALS['transaction_level'] ?? 0) > 0) {
            throw new \LogicException('Invoices are emailed after the transaction that wrote them has committed.');
        }
        FaIncludes::billing();
        $numbers = [];
        foreach (array_values($ids) as $index => $id) {
            $no = IntKey::parse($id, 'id');
            $this->assertEmailable($no, $index);
            $numbers[] = $no;
        }
        $login = (string) $this->session->user()->loginname;
        $company = CompanyContext::company();
        $results = [];
        foreach ($numbers as $no) {
            $run = $this->runner->run(self::argv($this->script, $company, $login, $no), $this->timeoutSeconds);
            $result = self::interpret($run, $this->timeoutSeconds);
            if (!$result['sent']) {
                error_log(sprintf(
                    'graphql: invoice %d not emailed; child exit %s; stdout %s; stderr %s',
                    $no,
                    $run->timedOut ? 'timeout' : (string) $run->exitCode,
                    substr($run->stdout, -2048),
                    substr($run->stderr, -2048)
                ));
            }
            $results[] = ['id' => $no] + $result;
        }
        return $results;
    }

    /** @return string[] */
    public static function argv(string $script, int $company, string $login, int $invoiceNo): array
    {
        return [self::phpBinary(), $script, '107', (string) $company, $login, (string) $invoiceNo, 'email'];
    }

    /**
     * The PHP CLI that runs the child. Under the CLI it is this PHP. Under a web SAPI
     * PHP_BINARY is empty or the web server itself (mod_php), so it is the CLI
     * installed beside this PHP: Debian's versioned php7.4 first, then php, then
     * `php` from PATH (proc_open with an argument array searches PATH).
     */
    public static function phpBinary(
        string $sapi = PHP_SAPI,
        string $binary = PHP_BINARY,
        string $bindir = PHP_BINDIR
    ): string {
        if ($sapi === 'cli' && $binary !== '') {
            return $binary;
        }
        foreach ([$bindir . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $bindir . '/php'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    /** @return array{sent: bool, recipient: ?string, messages: string[]} */
    public static function interpret(ReportRun $run, int $timeoutSeconds): array
    {
        $payload = null;
        foreach (preg_split('/\r\n|\n|\r/', $run->stdout) ?: [] as $line) {
            if (strpos($line, self::RESULT_PREFIX) === 0) {
                $decoded = json_decode(substr($line, strlen(self::RESULT_PREFIX)), true);
                $payload = is_array($decoded) && isset($decoded['messages']) && is_array($decoded['messages'])
                    ? $decoded
                    : null;
            }
        }
        if ($payload === null) {
            $messages = [self::NO_RESULT];
            if ($run->timedOut) {
                $messages[] = "It was stopped after $timeoutSeconds seconds.";
            }
            return ['sent' => false, 'recipient' => null, 'messages' => $messages];
        }
        $texts = [];
        $notified = false;
        $failed = false;
        $recipient = null;
        foreach ($payload['messages'] as $message) {
            $text = trim((string) ($message['text'] ?? ''));
            $level = (string) ($message['level'] ?? 'error');
            $texts[] = $text;
            if ($level === 'notice') {
                $notified = true;
                if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m) && $m[0]) {
                    $recipient = end($m[0]);
                }
            } else {
                $failed = true;
            }
        }
        $sent = $notified && !$failed && !$run->timedOut;
        return ['sent' => $sent, 'recipient' => $sent ? $recipient : null, 'messages' => $texts];
    }

    private function assertEmailable(int $no, int $index): void
    {
        $sql = 'SELECT t.trans_no, v.id AS voided FROM ' . TB_PREF . 'debtor_trans t'
            . ' LEFT JOIN ' . TB_PREF . 'voided v ON v.type = t.type AND v.id = t.trans_no'
            . ' WHERE t.type = ' . ST_SALESINVOICE . ' AND t.trans_no = ' . db_escape($no);
        $row = db_fetch(db_query($sql, 'could not read the invoice'));
        if (!$row) {
            throw new NotFound("Invoice $no does not exist.", $index);
        }
        if ($row['voided'] !== null) {
            throw new FaRejected("Invoice $no is voided and cannot be emailed.", ["Invoice $no is voided."], $index);
        }
    }
}
