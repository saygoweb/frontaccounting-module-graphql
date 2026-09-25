<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\ApiSchema;

class ApiSchemaWiringTest extends ApplicationTestCase
{
    public function testTheAppServesApiSchema(): void
    {
        $this->createApp();
        $response = $this->request('POST', '/', (string) json_encode(['query' => '{ apiVersion }']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => ['apiVersion' => ApiSchema::VERSION]], $this->json($response));
    }

    public function testMeWithoutATokenIsAGraphQLErrorNotAnHttpOne(): void
    {
        $this->createApp();
        $response = $this->request('POST', '/', (string) json_encode(['query' => '{ me { login } }']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('UNAUTHENTICATED', $this->json($response)['errors'][0]['extensions']['code']);
    }
}
