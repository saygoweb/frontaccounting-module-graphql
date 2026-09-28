<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\Unauthenticated;

class ApplicationTest extends ApplicationTestCase
{
    private const QUERY = '{"query": "{ apiVersion }"}';

    public function testApiVersionResolvesAnonymously(): void
    {
        $gate = new FakeSessionGate();
        $this->createApp($gate);

        $response = $this->request('POST', '/', self::QUERY);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => ['apiVersion' => '0.1.0']], $this->json($response));
        $this->assertSame(1, $gate->boots);
        $this->assertSame([], $gate->entered);
    }

    public function testIndexPhpIsTheSameEndpoint(): void
    {
        $response = $this->request('POST', '/index.php', self::QUERY);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('0.1.0', $this->json($response)['data']['apiVersion']);
    }

    public function testGetOnIndexPhpIs405Too(): void
    {
        $response = $this->request('GET', '/index.php');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('METHOD_NOT_ALLOWED', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testGetIs405AsJson(): void
    {
        $response = $this->request('GET', '/');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
        $this->assertSame('METHOD_NOT_ALLOWED', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testBrowserGetIsVoyager(): void
    {
        $gate = new FakeSessionGate();
        $this->createApp($gate);

        foreach (['/', '/index.php'] as $path) {
            $response = $this->request('GET', $path, '', ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);

            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
            $html = (string) $response->getBody();
            $this->assertStringContainsString('graphql-voyager@2.1.0/dist/voyager.standalone.js', $html);
            $this->assertStringContainsString('integrity="sha384-', $html);
            $this->assertStringContainsString('GraphQLVoyager.renderVoyager', $html);
        }
        // The page is static: no FrontAccounting boot for it.
        $this->assertSame(0, $gate->boots);
    }

    public function testGetForJsonIs405EvenWithVoyager(): void
    {
        $response = $this->request('GET', '/', '', ['Accept' => 'application/json']);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
        $this->assertSame('METHOD_NOT_ALLOWED', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testVoyagerOffMakesBrowserGet405(): void
    {
        $this->createApp(null, ['voyager' => false]);

        $response = $this->request('GET', '/', '', ['Accept' => 'text/html']);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
        $this->assertSame('METHOD_NOT_ALLOWED', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testUnknownPathIs404AsJson(): void
    {
        $response = $this->request('POST', '/nope', self::QUERY);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('NOT_FOUND', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testBadBodyIs400(): void
    {
        $response = $this->request('POST', '/', '{"nope": 1}');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('BAD_REQUEST', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testBadTokenWinsOverBadBody(): void
    {
        $response = $this->request('POST', '/', 'not json', ['Authorization' => 'Bearer nonsense']);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('UNAUTHENTICATED', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testOversizeBodyIs413(): void
    {
        $this->createApp(null, ['max_body_bytes' => 100]);

        $response = $this->request('POST', '/', '{"query": "{ apiVersion } # ' . str_repeat('x', 200) . '"}');

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('PAYLOAD_TOO_LARGE', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testBearerTokenEntersTheSession(): void
    {
        $gate = new FakeSessionGate();
        $this->createApp($gate);

        $response = $this->request('POST', '/', self::QUERY, ['Authorization' => 'Bearer ' . $this->token(2)]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $gate->entered);
        $this->assertSame(2, $gate->entered[0]->company);
        $this->assertSame('apitest', $gate->entered[0]->login);
    }

    public function testGateRefusingTheIdentityIs401(): void
    {
        $gate = new FakeSessionGate();
        $gate->throwOnEnter = new Unauthenticated('This user can no longer sign in.');
        $this->createApp($gate);

        $response = $this->request('POST', '/', self::QUERY, ['Authorization' => 'Bearer ' . $this->token()]);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('This user can no longer sign in.', $this->json($response)['errors'][0]['message']);
    }

    public function testGateForbiddingTheIdentityIs403(): void
    {
        $gate = new FakeSessionGate();
        $gate->throwOnEnter = new Forbidden('No GraphQL API access.');
        $this->createApp($gate);

        $response = $this->request('POST', '/', self::QUERY, ['Authorization' => 'Bearer ' . $this->token(0, 'noapi')]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('FORBIDDEN', $this->json($response)['errors'][0]['extensions']['code']);
    }

    public function testAPhpErrorInThePipelineIs500Json(): void
    {
        $gate = new FakeSessionGate();
        $gate->throwOnBoot = new \TypeError('Argument 1 must be of type int, string given');
        $this->createApp($gate);

        $response = $this->request('POST', '/', self::QUERY);

        $this->assertSame(500, $response->getStatusCode());
        $body = $this->json($response);
        $this->assertSame('INTERNAL', $body['errors'][0]['extensions']['code']);
        $this->assertStringNotContainsString('Argument 1', (string) json_encode($body));
    }
}
