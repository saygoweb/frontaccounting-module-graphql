<?php

namespace FA\GraphQL;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The two facts about the HTTP request that resolvers need, taken from the PSR-7
 * request once, in index.php, so nothing else reads a superglobal.
 */
final class RequestInfo
{
    public bool $https;
    public string $client;

    public function __construct(bool $https, string $client)
    {
        $this->https = $https;
        $this->client = $client;
    }

    public static function fromRequest(ServerRequestInterface $request, Config $config): self
    {
        $server = $request->getServerParams();
        $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && strtolower((string) $server['HTTPS']) !== 'off';
        $address = (string) ($server['REMOTE_ADDR'] ?? '');

        if ($config->trustProxy) {
            if (strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https') {
                $https = true;
            }
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $address = trim(explode(',', $forwarded)[0]);
            }
        }

        $agent = substr($request->getHeaderLine('User-Agent'), 0, 200);

        return new self($https, substr(trim($agent . ' ' . $address), 0, 255));
    }
}
