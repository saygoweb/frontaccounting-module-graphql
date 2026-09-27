<?php

namespace FA\GraphQL\Tests\Unit\Cli;

use FA\GraphQL\Fa\Service\ReportRunner;
use PHPUnit\Framework\TestCase;

/**
 * bin/fa-token's own guards, which run before FrontAccounting or the configuration
 * is touched: it refuses any SAPI but the CLI, and any malformed command line.
 */
class FaTokenScriptTest extends TestCase
{
    private function script(): string
    {
        return dirname(__DIR__, 3) . '/bin/fa-token';
    }

    /**
     * @return array<string, array{0: string[]}>
     */
    public function refusedUsages(): array
    {
        return [
            'no arguments' => [[]],
            'an unknown command' => [['mint', '--company', '0']],
            'issue without --label' => [['issue', '--company', '0', '--user', 'sgwpanel', '--days', '30']],
            'issue with an empty label' => [['issue', '--company', '0', '--user', 'sgwpanel', '--days', '30',
                '--label', ' ']],
            'days that are not a number' => [['issue', '--company', '0', '--user', 'sgwpanel', '--days', '1y',
                '--label', 'x']],
            'a company that is not a number' => [['list', '--company', '0;id']],
            'a negative company' => [['list', '--company=-1']],
            'an unknown option' => [['list', '--company', '0', '--all', 'yes']],
            'an option twice' => [['list', '--company', '0', '--company', '1']],
            'an option without a value' => [['list', '--company']],
            'revoke without a jti' => [['revoke', '--company', '0']],
            'revoke with two' => [['revoke', '--company', '0', 'a', 'b']],
            'list with a stray argument' => [['list', '--company', '0', 'x']],
        ];
    }

    /**
     * @dataProvider refusedUsages
     * @param string[] $args
     */
    public function testAMalformedCommandLineIsRefused(array $args): void
    {
        $run = (new ReportRunner())->run(array_merge([PHP_BINARY, $this->script()], $args), 10);

        $this->assertSame(2, $run->exitCode, $run->stderr);
        $this->assertSame('', $run->stdout);
        $this->assertStringContainsString('usage: fa-token', $run->stderr);
    }

    public function testAWebSapiIsRefused(): void
    {
        // As FaReportScriptTest: the built-in web server (cli-server) runs the script
        // through a router, the way a misconfigured web server would.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $router = tempnam(sys_get_temp_dir(), 'fa-token-router');
        file_put_contents($router, '<?php $argv = ["fa-token", "list", "--company", "0"]; require '
            . var_export($this->script(), true) . ';');

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
                $body = @file_get_contents("http://$address/fa-token", false, $context);
                $headers = $http_response_header ?? [];
            }
            $this->assertIsString($body, 'the built-in server did not answer');
            $this->assertStringContainsString(' 404', $headers[0]);
            $this->assertStringNotContainsString('jti', $body);
            $this->assertStringNotContainsString('usage', $body);
            $this->assertStringNotContainsString('<?php', $body, 'the source is not served either');
        } finally {
            proc_terminate($server, 9);
            proc_close($server);
            unlink($router);
        }
    }
}
