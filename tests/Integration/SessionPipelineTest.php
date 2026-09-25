<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Tests\Unit\Http\ApplicationTestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SessionPipelineTest extends ApplicationTestCase
{
    protected function setUp(): void
    {
        if (!is_file(Bootstrap::defaultRoot() . '/includes/session_utils.inc')) {
            $this->markTestSkipped('No FrontAccounting fork here; run in the docker stack.');
        }
        parent::setUp();
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function asUser(string $login): array
    {
        $app = $this->createApp(new FaSession(Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef'])));
        $token = $app->getContainer()->get(TokenService::class)->issueAccess(0, $login);
        $response = $this->request('POST', '/', '{"query":"{ apiVersion }"}', ['Authorization' => "Bearer $token"]);

        return [$response->getStatusCode(), $this->json($response)];
    }

    public function testAUserWithTheApiAreaGetsThrough(): void
    {
        [$status, $body] = $this->asUser('apitest');

        $this->assertSame(200, $status);
        $this->assertSame('0.1.0', $body['data']['apiVersion']);
    }

    /**
     * tmp/faillog.php is the web UI's brute-force throttle. Entering a session
     * from a verified token is not a login attempt and must neither reset nor
     * rewrite it.
     */
    public function testAnAuthenticatedRequestLeavesTheWebUisFailLogAlone(): void
    {
        $file = Bootstrap::defaultRoot() . '/tmp/faillog.php';
        $original = is_file($file) ? file_get_contents($file) : null;
        $seeded = "<?php\n\$login_faillog = array (0 => array ('10.9.8.7' => 4, 'last' => 1790301030));\n";
        file_put_contents($file, $seeded);
        touch($file, 1700000000);
        clearstatcache();
        try {
            $_SERVER['REMOTE_ADDR'] = '10.9.8.7';
            [$status] = $this->asUser('apitest');

            $this->assertSame(200, $status);
            clearstatcache();
            $this->assertSame($seeded, file_get_contents($file));
            $this->assertSame(1700000000, filemtime($file), 'faillog.php was rewritten');
        } finally {
            if ($original === null) {
                unlink($file);
            } else {
                file_put_contents($file, $original);
            }
        }
    }

    public function testAnIdentityTheGateRefusesIs401(): void
    {
        [$status, $body] = $this->asUser('nobody');

        $this->assertSame(401, $status);
        $this->assertSame('UNAUTHENTICATED', $body['errors'][0]['extensions']['code']);
    }

    public function testARoleWithoutTheApiAreaIs403(): void
    {
        [$status, $body] = $this->asUser('noapi');

        $this->assertSame(403, $status);
        $this->assertSame('FORBIDDEN', $body['errors'][0]['extensions']['code']);
    }

    /**
     * @return array<string, array{string, array<string, string>, array<string, string>}>
     */
    public function hostileInputs(): array
    {
        return [
            // Ajax (JsHttpRequest) would install an output handler that rewrites the
            // body as JavaScript, and switch display_errors on.
            'JsHttpRequest script loader' => ['JsHttpRequest=1-script', ['JsHttpRequest' => '1-script'], []],
            'JsHttpRequest xml loader' => ['JsHttpRequest=1-xml', ['JsHttpRequest' => '1-xml'], []],
            // frontaccounting.php, config.php and language.inc die("Restricted access").
            'path_to_root in the query' => ['path_to_root=x', ['path_to_root' => 'x'], []],
            'path_to_root in a form body' => ['', [], ['path_to_root' => 'x']],
        ];
    }

    /**
     * The API reads its request through PSR-7; FrontAccounting must not see the
     * HTTP request at all, so nothing in it can change what Bootstrap emits.
     *
     * @dataProvider hostileInputs
     * @param array<string, string> $get
     * @param array<string, string> $post
     */
    public function testRequestInputCannotChangeWhatFrontAccountingEmits(string $query, array $get, array $post): void
    {
        // As a web SAPI would have filled them for this request.
        $_SERVER['QUERY_STRING'] = $query;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = $get;
        $_POST = $post;
        $_REQUEST = $get + $post;
        $obLevel = ob_get_level();
        ini_set('display_errors', '0');

        $this->createApp(new FaSession(Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef'])));
        $response = $this->request('POST', '/' . ($query !== '' ? '?' . $query : ''), '{"query":"{ apiVersion }"}');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => ['apiVersion' => '0.1.0']], $this->json($response));
        $this->assertSame($obLevel, ob_get_level(), 'FrontAccounting left an output handler installed');
        $this->assertSame([], headers_list());
        $this->assertContains(ini_get('display_errors'), ['0', '', 'Off', 'off']);
        $this->assertEmpty($GLOBALS['JsHttpRequest_Active'] ?? null, 'Ajax thinks this is an AJAX request');
        $this->assertSame([], $_GET);
        $this->assertSame([], $_POST);
        $this->assertSame([], $_REQUEST);
    }
}
