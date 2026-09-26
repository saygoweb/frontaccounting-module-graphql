<?php

namespace FA\GraphQL\Tests\Unit\Fa\Service;

use FA\GraphQL\Fa\Service\ReportRunner;
use PHPUnit\Framework\TestCase;

/**
 * bin/fa-report's own guards, which run before FrontAccounting is touched: it
 * refuses any SAPI but the CLI, and any usage but the one InvoiceMailer makes.
 */
class FaReportScriptTest extends TestCase
{
    private function script(): string
    {
        return dirname(__DIR__, 4) . '/bin/fa-report';
    }

    /**
     * @return array<string, array{0: string[]}>
     */
    public function refusedUsages(): array
    {
        return [
            'no arguments' => [[]],
            'another report' => [['108', '0', 'apitest', '1', 'email']],
            'a company that is not a number' => [['107', '0;id', 'apitest', '1', 'email']],
            'an empty login' => [['107', '0', '', '1', 'email']],
            'an invoice that is not a number' => [['107', '0', 'apitest', '1-10', 'email']],
            'not email' => [['107', '0', 'apitest', '1', 'print']],
            'an extra argument' => [['107', '0', 'apitest', '1', 'email', 'x']],
        ];
    }

    /**
     * @dataProvider refusedUsages
     * @param string[] $args
     */
    public function testAnyOtherUsageIsRefused(array $args): void
    {
        $run = (new ReportRunner())->run(array_merge([PHP_BINARY, $this->script()], $args), 10);

        $this->assertSame(2, $run->exitCode);
        $this->assertSame('', $run->stdout);
        $this->assertStringContainsString('usage: fa-report', $run->stderr);
    }

    public function testAWebSapiIsRefused(): void
    {
        // PHP's built-in web server is a SAPI other than cli (cli-server): the script
        // must answer 404 and do nothing, not even complain about its arguments.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        // The built-in server serves an extensionless file as it is, so a router
        // runs the script, the way a misconfigured web server would.
        $router = tempnam(sys_get_temp_dir(), 'fa-report-router');
        file_put_contents($router, '<?php require ' . var_export($this->script(), true) . ';');

        $pipes = [];
        $server = proc_open(
            [PHP_BINARY, '-S', $address, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertIsResource($server);
        try {
            $body = false;
            $headers = [];
            $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
            for ($i = 0; $i < 50 && $body === false; $i++) {
                usleep(100000);
                $body = @file_get_contents("http://$address/fa-report", false, $context);
                $headers = $http_response_header ?? [];
            }
            $this->assertIsString($body, 'the built-in server did not answer');
            $this->assertStringContainsString(' 404', $headers[0]);
            $this->assertStringNotContainsString('usage', $body);
            $this->assertStringNotContainsString('FA_REPORT_RESULT', $body);
            $this->assertStringNotContainsString('<?php', $body, 'the source is not served either');
        } finally {
            proc_terminate($server, 9);
            proc_close($server);
            unlink($router);
        }
    }
}
