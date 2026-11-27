<?php

namespace App\Domain\Integrations\Jira;

use Cube\Web\Http\HttpClient;
use Cube\Web\Http\Response;

class JiraClient extends HttpClient
{
    /**
     * @param string $site https://<name>.atlassian.net
     */
    public function __construct(
        public readonly string $site,
        private readonly string $email,
        #[\SensitiveParameter]
        private readonly string $token,
    ) {}

    public function baseURL(): ?string
    {
        return $this->site;
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

    public function describeFailure(Response $response, string $path): string
    {
        return 'Jira answered '.$response->getStatusCode().' on '.preg_replace('/\?.*/', '', $path);
    }
}
