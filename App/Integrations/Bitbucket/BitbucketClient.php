<?php

namespace App\Integrations\Bitbucket;

use Cube\Web\Http\HttpClient;
use Cube\Web\Http\Response;

class BitbucketClient extends HttpClient
{
    public function __construct(
        private readonly string $email,
        #[\SensitiveParameter]
        private readonly string $token,
    ) {}

    public function baseURL(): ?string
    {
        return 'https://api.bitbucket.org/2.0';
    }

    public function baseHeaders(): array
    {
        return ['Authorization' => 'Basic '.base64_encode($this->email.':'.$this->token), 'Accept' => 'application/json'];
    }

    public function baseUserAgent(): string
    {
        return 'tower';
    }

    /**
     * @return ?array null on a 404
     */
    public function read(string $path, array $parameters = []): ?array
    {
        $response = $this->get($path, $parameters);

        return match ($response->getStatusCode()) {
            200 => $response->getJSON(),
            404 => null,
            default => throw new \RuntimeException($this->describeFailure($response, $path)),
        };
    }

    /**
     * @return ?string The body of a text answer (a diff), null on a 404
     */
    public function readText(string $path, array $parameters = []): ?string
    {
        $response = $this->get($path, $parameters, ['Accept' => 'text/plain']);

        return match ($response->getStatusCode()) {
            200 => $response->getBody(),
            404 => null,
            default => throw new \RuntimeException($this->describeFailure($response, $path)),
        };
    }

    /**
     * @return list<array> The values of every page
     */
    public function readAll(string $path, array $parameters = []): array
    {
        $values = [];
        $page = $this->read($path, $parameters);
        if (null === $page)
            throw new \RuntimeException("Bitbucket does not know {$path}");

        while (true) {
            array_push($values, ...($page['values'] ?? []));
            if (!isset($page['next']))
                return $values;

            $page = $this->read($page['next']);
            if (null === $page)
                return $values;
        }
    }

    private function describeFailure(Response $response, string $path): string
    {
        return 'Bitbucket answered '.$response->getStatusCode().' on '.preg_replace('/\?.*/', '', $path);
    }
}
