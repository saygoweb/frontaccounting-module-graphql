<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Http\OutputCapture;
use PHPUnit\Framework\TestCase;

/**
 * OutputCapture over real HTTP: fixtures/capture.php served by PHP's built-in web
 * server, so the status line, the headers and the body are what a client gets.
 */
class OutputCaptureServerTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    private static string $log = '';

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        if ($probe === false) {
            self::markTestSkipped('No local port to serve the fixture on.');
        }
        $name = (string) stream_socket_get_name($probe, false);
        self::$port = (int) substr((string) strrchr($name, ':'), 1);
        fclose($probe);

        self::$log = (string) tempnam(sys_get_temp_dir(), 'capture-server-log');
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, '-t', __DIR__ . '/fixtures'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['CAPTURE_LOG' => self::$log])
        );
        if (!is_resource($server)) {
            self::markTestSkipped('Could not start PHP\'s built-in web server.');
        }
        self::$server = $server;

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100000);
        }
        self::fail('The built-in web server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
        @unlink(self::$log);
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function get(string $case): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $url = 'http://127.0.0.1:' . self::$port . '/capture.php?case=' . $case;
        $body = (string) file_get_contents($url, false, $context);
        $status = (int) explode(' ', $http_response_header[0])[1];
        $headers = [];
        foreach (array_slice($http_response_header, 1) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            $headers[strtolower($name)] = $value;
        }

        return [$status, $headers, $body];
    }

    /**
     * @return array<string, array{string}>
     */
    public function endsBeforeTheResponse(): array
    {
        return [
            'exit' => ['exit'],
            'die' => ['die'],
            'fatal error' => ['fatal'],
            'E_USER_ERROR' => ['user-error'],
            'a 401 header, then exit' => ['header-then-exit'],
            'a later shutdown function prints, then exit' => ['late-then-exit'],
        ];
    }

    /**
     * @dataProvider endsBeforeTheResponse
     */
    public function testARequestThatEndsEarlyGetsTheFixedJson500AndNothingElse(string $case): void
    {
        [$status, $headers, $body] = $this->get($case);

        $this->assertSame(500, $status);
        $this->assertSame(OutputCapture::BODY, $body);
        $this->assertStringStartsWith('application/json', $headers['content-type'] ?? '');
        $this->assertArrayNotHasKey('x-frontaccounting', $headers);
    }

    /**
     * @return array<string, array{string}>
     */
    public function misbehavesButCompletes(): array
    {
        return [
            'prints only' => ['none'],
            'sets a header' => ['normal-header'],
            'leaves a handler buffer open' => ['handler'],
            'prints from a later shutdown function' => ['late'],
        ];
    }

    /**
     * @dataProvider misbehavesButCompletes
     */
    public function testACompletedRequestSendsOnlyItsResponse(string $case): void
    {
        [$status, $headers, $body] = $this->get($case);

        $this->assertSame(200, $status);
        $this->assertSame('{"data":{"ok":true}}', $body);
        $this->assertArrayNotHasKey('x-frontaccounting', $headers);
    }

    public function testWhatWasDiscardedIsLogged(): void
    {
        $this->get('fatal');

        $log = (string) file_get_contents(self::$log);
        $this->assertStringContainsString('FrontAccounting says hello', $log);
        $this->assertStringContainsString('undefined_function_for_the_capture_test', $log);
    }
}
