<?php

namespace Tests\Integration;

use App\Documentation\DocumentationService;
use App\Models\DocImage;
use App\Models\DocPage;
use App\Models\DocPageVersion;
use App\Models\History;
use App\Models\User;
use App\Services\HistoryConfiguration;
use App\Services\WorkspaceSignals;
use App\Session\SelectedWorkspace;
use Cube\Env\Storage;
use Cube\Security\Authentication;
use Cube\Test\CubeTestCase;
use Cube\Web\Websocket\Broadcast\Broadcast;
use Cube\Web\Websocket\Broadcast\BroadcastConfiguration;

class DocumentationTest extends CubeTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Broadcast $originalBroadcast;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logOut();

        // Never reach the real websocket server
        $this->originalBroadcast = Broadcast::getInstance();
        Broadcast::setInstance(new class(new BroadcastConfiguration()) extends Broadcast {
            public function emit(string $event, array $data): bool
            {
                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        Broadcast::setInstance($this->originalBroadcast);
        parent::tearDown();
    }

    private function logOut(): void
    {
        Authentication::getInstance()->logout();
        SelectedWorkspace::unset();
    }

    private function register(string $email = 'alice@example.com'): void
    {
        $this->postJson('/api/auth/register', ['email' => $email, 'password' => 'correct horse battery'])->assertCreated();
    }

    private function page(string $title, string $content = '', ?int $parent = null, array $tags = []): array
    {
        return $this->postJson('/api/docs/pages', ['title' => $title, 'content' => $content, 'parent' => $parent, 'tags' => $tags])->assertCreated()->json();
    }

    private function titles(array $query = []): array
    {
        return array_column($this->get('/api/docs/pages'.($query ? '?'.http_build_query($query) : ''))->assertOk()->json(), 'title');
    }

    public function testRoutesRequireAuthentication()
    {
        $this->get('/api/docs/pages')->assertUnauthorized();
        $this->postJson('/api/docs/pages', ['title' => 'Setup'])->assertUnauthorized();
    }

    public function testCreateAndReadAPage()
    {
        $this->register();
        $created = $this->page('Setup', "# Setup\n\n```bash\nmake dev\n```", tags: ['onboarding', ' ops ', 'onboarding']);

        $this->assertSame(1, $created['version']);
        $this->assertSame(['onboarding', 'ops'], $created['tags']);
        $this->assertSame('alice@example.com', $created['updated_by']);

        $read = $this->get("/api/docs/pages/{$created['id']}")->assertOk()->json();
        $this->assertSame("# Setup\n\n```bash\nmake dev\n```", $read['content']);

        $this->assertSame('doc.page.created', History::last()->type);
    }

    public function testTheListLeavesTheContentOutAndFilters()
    {
        $this->register();
        $setup = $this->page('Setup', 'Run make dev', tags: ['onboarding']);
        $this->page('Deploy', 'Run make deploy', tags: ['ops']);
        $this->page('Old', 'Forgotten');
        $this->putJson('/api/docs/pages/'.$this->page('Archived', 'Gone')['id'], ['archived' => true])->assertOk();

        $list = $this->get('/api/docs/pages')->assertOk()->json();
        $this->assertArrayNotHasKey('content', $list[0]);
        $this->assertSame(['Deploy', 'Old', 'Setup'], $this->titles());
        $this->assertSame(['Deploy', 'Setup'], $this->titles(['search' => 'make']));
        $this->assertSame(['Setup'], $this->titles(['tag' => 'onboarding']));
        $this->assertSame(['Archived', 'Deploy', 'Old', 'Setup'], $this->titles(['include_archived' => 'true']));
        $this->assertSame($setup['id'], $this->get('/api/docs/pages?tag=onboarding')->json()[0]['id']);
    }

    public function testPagesStayInTheirWorkspace()
    {
        $this->register();
        $page = $this->page('Private');
        $this->logOut();

        $this->register('bob@example.com');
        $this->get("/api/docs/pages/{$page['id']}")->assertNotFound();
        $this->putJson("/api/docs/pages/{$page['id']}", ['title' => 'Mine'])->assertNotFound();
        $this->postJson('/api/docs/pages', ['title' => 'Child', 'parent' => $page['id']])->assertUnprocessableContent();
        $this->assertSame([], $this->titles());
    }

    public function testAnUpdateMakesANewVersion()
    {
        $this->register();
        $page = $this->page('Setup', 'First');

        $updated = $this->putJson("/api/docs/pages/{$page['id']}", ['content' => 'Second', 'version' => 1])->assertOk()->json();
        $this->assertSame(2, $updated['version']);
        $this->assertSame('Setup', $updated['title']);

        $versions = $this->get("/api/docs/pages/{$page['id']}/versions")->assertOk()->json();
        $this->assertSame([2, 1], array_column($versions, 'version'));
        $this->assertSame('alice@example.com', $versions[0]['author']);
        $this->assertArrayNotHasKey('content', $versions[0]);

        $first = $this->get("/api/docs/versions/{$versions[1]['id']}")->assertOk()->json();
        $this->assertSame('First', $first['content']);
    }

    public function testAStaleVersionIsAConflict()
    {
        $this->register();
        $page = $this->page('Setup', 'First');
        $this->putJson("/api/docs/pages/{$page['id']}", ['content' => 'Second', 'version' => 1])->assertOk();

        $this->putJson("/api/docs/pages/{$page['id']}", ['content' => 'Overwrite', 'version' => 1])->assertConflict();
        $this->assertSame('Second', $this->get("/api/docs/pages/{$page['id']}")->json()['content']);
    }

    public function testMovingOrArchivingKeepsTheVersion()
    {
        $this->register();
        $parent = $this->page('Guides');
        $page = $this->page('Setup');

        $moved = $this->putJson("/api/docs/pages/{$page['id']}", ['parent' => $parent['id']])->assertOk()->json();
        $this->assertSame($parent['id'], $moved['parent']);
        $this->assertSame(1, $moved['version']);

        $archived = $this->putJson("/api/docs/pages/{$page['id']}", ['archived' => true])->assertOk()->json();
        $this->assertNotNull($archived['archived_at']);
        $this->assertSame(1, $archived['version']);

        $restored = $this->putJson("/api/docs/pages/{$page['id']}", ['archived' => false])->assertOk()->json();
        $this->assertNull($restored['archived_at']);
        $this->assertSame('doc.page.restored', History::last()->type);
    }

    public function testAPageCannotBeItsOwnAncestor()
    {
        $this->register();
        $root = $this->page('Root');
        $child = $this->page('Child', parent: $root['id']);

        $this->putJson("/api/docs/pages/{$root['id']}", ['parent' => $root['id']])->assertUnprocessableContent();
        $this->putJson("/api/docs/pages/{$root['id']}", ['parent' => $child['id']])->assertUnprocessableContent();
        $this->putJson("/api/docs/pages/{$child['id']}", ['parent' => null])->assertOk();
    }

    public function testEditsReplacePassagesFoundOnce()
    {
        $this->register();
        $page = $this->page('Setup', "Install PHP.\nRun make dev.\nRun make test.");

        $edited = $this->postJson("/api/docs/pages/{$page['id']}/edits", ['edits' => [
            ['old_text' => 'Install PHP.', 'new_text' => 'Install Docker.'],
            ['old_text' => 'make dev', 'new_text' => 'make up'],
        ], 'version' => 1])->assertOk()->json();
        $this->assertSame("Install Docker.\nRun make up.\nRun make test.", $edited['content']);
        $this->assertSame(2, $edited['version']);

        $this->postJson("/api/docs/pages/{$page['id']}/edits", ['edits' => [['old_text' => 'Run make', 'new_text' => 'Launch']]])->assertUnprocessableContent();
        $this->postJson("/api/docs/pages/{$page['id']}/edits", ['edits' => [
            ['old_text' => 'Install Docker.', 'new_text' => 'Nothing'],
            ['old_text' => 'missing', 'new_text' => 'x'],
        ]])->assertUnprocessableContent();
        $this->assertSame($edited['content'], $this->get("/api/docs/pages/{$page['id']}")->json()['content']);
    }

    public function testTheContentHasALimit()
    {
        $this->register();
        $this->postJson('/api/docs/pages', ['title' => 'Huge', 'content' => str_repeat('a', DocumentationService::MAX_CONTENT_BYTES + 1)])->assertUnprocessableContent();
    }

    public function testImagesAreStoredAndServed()
    {
        $this->register();
        $image = $this->postJson('/api/docs/images', ['name' => 'schema.png', 'content' => self::PNG])->assertCreated()->json();

        $this->assertSame("![schema.png](/api/docs/images/{$image['id']})", $image['markdown']);
        $served = $this->get($image['url'])->assertOk()->assertHeaderEqual('content-type', 'image/png');
        $this->assertSame(base64_decode(self::PNG), $served->body());

        $this->postJson('/api/docs/images', ['name' => 'evil.svg', 'content' => base64_encode('<svg onload="alert(1)"/>')])->assertUnprocessableContent();
        $this->postJson('/api/docs/images', ['name' => 'nope.png', 'content' => 'not base64 !'])->assertUnprocessableContent();
        $this->logOut();

        $this->register('bob@example.com');
        $this->get($image['url'])->assertNotFound();
    }

    public function testExpiredVersionsArePurgedButTheCurrentOne()
    {
        $this->register();
        $page = $this->page('Setup', 'First');
        $this->putJson("/api/docs/pages/{$page['id']}", ['content' => 'Second'])->assertOk();
        $this->database->exec("UPDATE doc_page_version SET created_at = '2000-01-01 00:00:00'");

        \inject(DocumentationService::class)->purgeExpiredVersions(new HistoryConfiguration());

        $this->assertSame([2], array_map(fn (DocPageVersion $version) => $version->version, DocPageVersion::select()->fetch()));
    }

    public function testTheAuthorDeletesAPageWithItsSubPages()
    {
        $this->register();
        $root = $this->page('Guides');
        $child = $this->page('Setup', 'First', parent: $root['id']);
        $this->page('Linux', parent: $child['id']);
        $this->page('Kept');
        $this->putJson("/api/docs/pages/{$child['id']}", ['content' => 'Second'])->assertOk();

        $this->delete("/api/docs/pages/{$root['id']}")->assertNoContent();

        $this->assertSame(['Kept'], $this->titles(['include_archived' => 'true']));
        $this->assertSame([], DocPageVersion::select()->whereIn('page', [$root['id'], $child['id']])->fetch());
        $line = History::select()->order('id', 'DESC')->first();
        $this->assertSame(['doc.page.deleted', 2], [$line->type, json_decode($line->details, true)['sub_pages']]);
    }

    public function testAnotherMemberCannotDeleteAPage()
    {
        $this->register('bob@example.com');
        $this->logOut();
        $this->register();
        $page = $this->page('Mine');
        $this->assertSame(User::findWhere(['email' => 'alice@example.com'])->id, $page['created_by']);
        $workspace = SelectedWorkspace::get();
        $this->postJson("/api/workspaces/{$workspace}/members", ['email' => 'bob@example.com'])->assertOk();
        $this->logOut();

        $this->postJson('/api/auth/login', ['email' => 'bob@example.com', 'password' => 'correct horse battery'])->assertOk();
        $this->putJson('/api/workspaces/current', ['workspace' => $workspace])->assertOk();
        $this->delete("/api/docs/pages/{$page['id']}")->assertForbidden();
        $this->assertSame(['Mine'], $this->titles());
    }

    public function testDeletingAWorkspaceDeletesItsDocumentation()
    {
        $this->register();
        $workspace = SelectedWorkspace::get();
        $root = $this->page('Guides');
        $this->page('Setup', parent: $root['id']);
        $image = $this->postJson('/api/docs/images', ['name' => 'schema.png', 'content' => self::PNG])->assertCreated()->json();
        $file = DocImage::find($image['id'])->file;

        $this->postJson('/api/workspaces', ['name' => 'Autre'])->assertCreated();
        $this->delete("/api/workspaces/{$workspace}")->assertNoContent();

        $this->assertSame([], DocPage::select()->where('workspace', $workspace)->fetch());
        $this->assertNull(DocImage::find($image['id']));
        $this->assertFalse(Storage::getInstance()->child('DocImages')->isFile($file));
    }

    public function testTheAuthorOfTheWorkspaceDeletesAnyPage()
    {
        $this->register('bob@example.com');
        $this->logOut();
        $this->register();
        $workspace = SelectedWorkspace::get();
        $this->postJson("/api/workspaces/{$workspace}/members", ['email' => 'bob@example.com'])->assertOk();
        $this->logOut();

        $this->postJson('/api/auth/login', ['email' => 'bob@example.com', 'password' => 'correct horse battery'])->assertOk();
        $this->putJson('/api/workspaces/current', ['workspace' => $workspace])->assertOk();
        $page = $this->page('Written by Bob');
        $this->logOut();

        $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => 'correct horse battery'])->assertOk();
        $this->putJson('/api/workspaces/current', ['workspace' => $workspace])->assertOk();
        $this->delete("/api/docs/pages/{$page['id']}")->assertNoContent();
        $this->assertSame([], $this->titles());
    }
}
