<?php

namespace Tests\Integration;

use App\Favicons\FaviconClient;
use App\Session\SelectedWorkspace;
use Cube\Env\Cache;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Http\HttpMockServer;
use Cube\Web\Http\MockServers;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class FaviconTest extends CubeTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    // Literal public addresses : no DNS lookup in the tests
    private const SITE = 'https://93.184.216.34';

    private Broadcast $originalBroadcast;

    /** @var list<string> */
    private array $reads = [];

    private string $homePage = '';

    protected function setUp(): void
    {
        parent::setUp();
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
        Cache::getInstance()->child('favicons')->clear();

        $this->originalBroadcast = Broadcast::getInstance();
        Broadcast::setInstance(new class(new BroadcastConfiguration()) extends Broadcast {
            public function emit(string $event, array $data): bool
            {
                return true;
            }
        });

        MockServers::getInstance()->set(FaviconClient::class, $this->siteServer()::fromArray([
            '/home' => fn (Request $request) => $this->answer($request, new Response(200, $this->homePage, ['Content-Type' => 'text/html'])),
            '/static/icon.png' => fn (Request $request) => $this->answer($request, new Response(200, base64_decode(self::PNG), ['Content-Type' => 'application/octet-stream'])),
            '/favicon.ico' => fn (Request $request) => $this->answer($request, new Response(200, base64_decode(self::PNG))),
            '/not-an-image.png' => fn (Request $request) => $this->answer($request, new Response(200, '<html>login</html>')),
        ]));

        $this->postJson('/api/auth/register', ['email' => 'alice@example.com', 'password' => 'correct horse battery'])->assertCreated();
    }

    protected function tearDown(): void
    {
        Broadcast::setInstance($this->originalBroadcast);
        parent::tearDown();
    }

    private function siteServer(): HttpMockServer
    {
        // The root of a site reaches the mock server as an empty path, which no route matches : it is served as /home
        return new class() extends HttpMockServer {
            protected function removeHostFromUrl(string $url): string
            {
                $path = parent::removeHostFromUrl($url);

                return in_array($path, ['', '/'], true) ? '/home' : $path;
            }
        };
    }

    private function answer(Request $request, Response $response): Response
    {
        $this->reads[] = '/home' === $request->getPath() ? '/' : $request->getPath();

        return $response;
    }

    private function link(string $url): int
    {
        $sprint = $this->postJson('/api/sprints', ['name' => 'Sprint'])->assertCreated()->json();

        return $this->postJson("/api/sprints/{$sprint['id']}/links", ['url' => $url])->assertCreated()->json()['id'];
    }

    public function testTheDeclaredIconIsReadOnceAndServedFromTheCache()
    {
        $this->homePage = '<html><head><link rel="apple-touch-icon" href="/touch.png"><link rel="shortcut icon" href="static/icon.png"></head></html>';
        $link = $this->link(self::SITE.'/board/12');

        $icon = $this->get("/api/sprints/links/{$link}/icon")->assertOk()->assertHeaderEqual('Content-Type', 'image/png');
        $this->assertSame(base64_decode(self::PNG), $icon->body());

        $this->get("/api/sprints/links/{$link}/icon")->assertOk();
        $this->assertSame(['/', '/static/icon.png'], $this->reads);
    }

    public function testWithoutDeclaredIconTheSiteFaviconIsRead()
    {
        $this->homePage = '<html><head><link rel="icon" href="/not-an-image.png"></head></html>';
        $link = $this->link(self::SITE.'/doc');

        $this->get("/api/sprints/links/{$link}/icon")->assertOk();
        $this->assertSame(['/', '/not-an-image.png', '/favicon.ico'], $this->reads);
    }

    public function testPrivateAddressesAreNeverRead()
    {
        $local = $this->link('http://127.0.0.1:8081/');
        $internal = $this->link('http://10.0.0.12/grafana');

        $this->get("/api/sprints/links/{$local}/icon")->assertNotFound();
        $this->get("/api/sprints/links/{$internal}/icon")->assertNotFound();
        $this->assertSame([], $this->reads);
    }
}
