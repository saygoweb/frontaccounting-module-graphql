<?php

namespace FA\GraphQL\Tests\Http;

trait GraphQLClient
{
    private function url(): string
    {
        $url = getenv('FA_GRAPHQL_URL');

        return $url !== false && $url !== '' ? $url : 'http://localhost:8000/modules/graphql/';
    }

    /**
     * A GraphQL POST. $path is appended to the module URL: '' for the directory,
     * 'index.php' for the script itself.
     *
     * @param array<string, mixed> $variables
     * @return array{status: int, body: array<string, mixed>|null, raw: string, contentType: string}
     */
    private function gql(string $query, array $variables = [], ?string $token = null, string $path = ''): array
    {
        $headers = "Content-Type: application/json\r\nUser-Agent: fa-graphql-tests\r\n";
        if ($token !== null) {
            $headers .= "Authorization: Bearer $token\r\n";
        }
        $response = $this->exchange(
            'POST',
            $path,
            $headers,
            (string) json_encode(['query' => $query, 'variables' => (object) $variables])
        );
        $this->assertIsArray($response['body'], 'the response was not JSON: ' . substr($response['raw'], 0, 300));

        return $response;
    }

    /**
     * Any request, for the routing tests. The body is decoded when it is JSON.
     *
     * @return array{status: int, body: array<string, mixed>|null, raw: string, contentType: string}
     */
    private function send(string $method, string $path, ?string $body = null): array
    {
        $headers = "User-Agent: fa-graphql-tests\r\n";
        if ($body !== null) {
            $headers .= "Content-Type: application/json\r\n";
        }

        return $this->exchange($method, $path, $headers, $body);
    }

    /**
     * @return array{status: int, body: array<string, mixed>|null, raw: string, contentType: string}
     */
    private function exchange(string $method, string $path, string $headers, ?string $body): array
    {
        $options = ['method' => $method, 'header' => $headers, 'ignore_errors' => true, 'timeout' => 20];
        if ($body !== null) {
            $options['content'] = $body;
        }
        $raw = file_get_contents($this->url() . $path, false, stream_context_create(['http' => $options]));
        $this->assertIsString($raw, 'no response from ' . $this->url() . $path);
        preg_match('#^HTTP/\S+ (\d{3})#', $http_response_header[0], $m);
        $contentType = '';
        foreach ($http_response_header as $line) {
            if (stripos($line, 'Content-Type:') === 0) {
                $contentType = trim(substr($line, 13));
            }
        }
        $decoded = json_decode($raw, true);

        return [
            'status' => (int) $m[1],
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => $raw,
            'contentType' => $contentType,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function login(string $user = 'apitest'): array
    {
        $response = $this->gql(
            'mutation ($u: String!, $p: String!) { login(user: $u, password: $p) '
                . '{ accessToken expiresIn refreshToken } }',
            ['u' => $user, 'p' => 'password']
        );
        $this->assertArrayNotHasKey('errors', $response['body'], $response['raw']);

        return $response['body']['data']['login'];
    }
}
