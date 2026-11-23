<?php

namespace App\Controllers;

use App\Documentation\DocumentationImages;
use App\Documentation\DocumentationRefusal;
use App\Documentation\DocumentationService;
use App\Mcp\McpTool;
use App\Middlewares\IsAuthenticated;
use App\Models\DocPage;
use App\Requests\CreateDocPageRequest;
use App\Requests\EditDocPageRequest;
use App\Requests\ListDocPagesRequest;
use App\Requests\UpdateDocPageRequest;
use App\Requests\UploadDocImageRequest;
use App\Resources\DocImageResource;
use App\Resources\DocPageResource;
use App\Resources\DocPageSummaryResource;
use App\Resources\DocPageVersionResource;
use App\Resources\DocPageVersionSummaryResource;
use App\Services\WorkspaceService;
use Cube\Data\OpenAPI\Attributes\Endpoint;
use Cube\Data\OpenAPI\Attributes\NoContentResponse;
use Cube\Data\OpenAPI\Attributes\RawResponse;
use Cube\Data\OpenAPI\Attributes\ResourceResponse;
use Cube\Security\Authentication;
use Cube\Web\Controller;
use Cube\Web\Http\Request;
use Cube\Web\Http\Response;
use Cube\Web\Http\StatusCode;
use Cube\Web\Router\Route;
use Cube\Web\Router\Router;

class DocumentationController extends Controller
{
    public function __construct(
        protected Authentication $authentication,
        protected WorkspaceService $workspaces,
        protected DocumentationService $documentation,
        protected DocumentationImages $images,
    ) {}

    public function routes(Router $router): void
    {
        $router->group('/api/docs', middlewares: [IsAuthenticated::class], routes: [
            Route::get('/pages', [self::class, 'list']),
            Route::post('/pages', [self::class, 'create']),
            Route::get('/pages/{int:page}', [self::class, 'read']),
            Route::put('/pages/{int:page}', [self::class, 'update']),
            Route::delete('/pages/{int:page}', [self::class, 'delete']),
            Route::post('/pages/{int:page}/edits', [self::class, 'edit']),
            Route::get('/pages/{int:page}/versions', [self::class, 'versions']),
            Route::get('/versions/{int:version}', [self::class, 'readVersion']),
            Route::post('/images', [self::class, 'uploadImage']),
            Route::get('/images/{int:image}', [self::class, 'image']),
        ]);
    }

    #[Endpoint('List documentation pages', 'The pages of the documentation of the current workspace, sorted by title, without their content : read one with readDocPage. A page with a parent is a sub-page of it. Narrow the list with search (in the title and the content), tag, and include_archived to see archived pages too', 'listDocPages')]
    #[ResourceResponse(DocPageSummaryResource::class, isArray: true, description: 'The pages')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[McpTool('list_doc_pages')]
    public function list(ListDocPagesRequest $request): Response
    {
        return Response::json(DocPageSummaryResource::of($this->documentation->listPages(
            $this->workspace(),
            $request->validated('search'),
            $request->validated('tag'),
            $request->validated('include_archived') ?? false,
        )));
    }

    #[Endpoint('Create documentation page', 'Adds a page to the documentation of the current workspace. The content is Markdown : fenced code blocks are highlighted, ```mermaid blocks are drawn as diagrams, and images uploaded with uploadDocImage are shown with their markdown snippet. Give a parent to make it a sub-page', 'createDocPage')]
    #[ResourceResponse(DocPageResource::class, responseCode: StatusCode::CREATED, description: 'The page, at version 1')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Page 12 cannot be the parent'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Unknown parent, or an invalid field')]
    #[McpTool('create_doc_page', write: true)]
    public function create(CreateDocPageRequest $request): Response
    {
        $page = $this->documentation->createPage(
            $this->workspace(),
            $this->authentication->userId(),
            $request->validated('title'),
            $request->validated('content') ?? '',
            $request->validated('parent'),
            $request->validated('tags') ?? [],
        );

        return $page instanceof DocumentationRefusal ? $this->refused($page) : Response::json(DocPageResource::of($page), StatusCode::CREATED);
    }

    #[Endpoint('Read documentation page', 'A page of the documentation with its Markdown content and its version : give that version back when changing the page, so that a change made in the meantime is not overwritten', 'readDocPage')]
    #[ResourceResponse(DocPageResource::class, description: 'The page')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Page not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such page in the current workspace')]
    #[McpTool('get_doc_page')]
    public function read(Request $request, int $page): Response
    {
        $found = $this->documentation->findPage($this->workspace(), $page);

        return $found ? Response::json(DocPageResource::of($found)) : $this->pageNotFound();
    }

    #[Endpoint('Update documentation page', 'Changes the title, the whole content, the tags, the parent (null for a top-level page) or the archived state of a page. A left out field is kept. Give the version read with readDocPage : the change is refused with a 409 if the page changed since. To change a few passages of a long page, prefer editDocPage', 'updateDocPage')]
    #[ResourceResponse(DocPageResource::class, description: 'The page')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Page not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such page in the current workspace')]
    #[RawResponse(['error' => 'The page changed since version 3'], responseCode: StatusCode::CONFLICT, description: 'The page is no longer at the given version')]
    #[RawResponse(['error' => 'Page 12 cannot be the parent'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Unknown parent, the page itself or one of its sub-pages, or an invalid field')]
    #[McpTool('update_doc_page', write: true)]
    public function update(UpdateDocPageRequest $request, int $page): Response
    {
        $found = $this->documentation->findPage($this->workspace(), $page);
        if (!$found)
            return $this->pageNotFound();

        $values = $request->validated();
        $updated = $this->documentation->updatePage(
            $found,
            $this->authentication->userId(),
            $values['version'] ?? null,
            $values['title'] ?? $found->title,
            $values['content'] ?? $found->content,
            $values['tags'] ?? DocumentationService::tagsOf($found),
            array_key_exists('parent', $values) ? $values['parent'] : $found->parent,
            $values['archived'] ?? null !== $found->archived_at,
        );

        return $this->answer($updated);
    }

    #[Endpoint('Delete documentation page', 'Deletes a page with all its sub-pages and their versions. Only the member who created the page, or the author of the workspace, can delete it', 'deleteDocPage')]
    #[NoContentResponse('The page and its sub-pages are deleted')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Only the member who created page 12, or the author of the workspace, can delete it'], responseCode: StatusCode::FORBIDDEN, description: 'The current user neither created the page nor the workspace')]
    #[RawResponse(['error' => 'Page not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such page in the current workspace')]
    public function delete(Request $request, int $page): Response
    {
        $found = $this->documentation->findPage($this->workspace(), $page);
        if (!$found)
            return $this->pageNotFound();

        $refusal = $this->documentation->deletePage($found, $this->authentication->userId());

        return $refusal ? $this->refused($refusal) : Response::noContent();
    }

    #[Endpoint('Edit documentation page', 'Replaces passages of the content of a page : each old_text must appear exactly once in the content, and is replaced by its new_text. The edits apply in order, all or none. Give the version read with readDocPage to be refused with a 409 if the page changed since', 'editDocPage')]
    #[ResourceResponse(DocPageResource::class, description: 'The page')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Page not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such page in the current workspace')]
    #[RawResponse(['error' => 'The page changed since version 3'], responseCode: StatusCode::CONFLICT, description: 'The page is no longer at the given version')]
    #[RawResponse(['error' => 'edits.0.old_text must appear exactly once in the content, found 0 times'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'A passage is missing or ambiguous, or the content would be too long')]
    #[McpTool('edit_doc_page', write: true)]
    public function edit(EditDocPageRequest $request, int $page): Response
    {
        $found = $this->documentation->findPage($this->workspace(), $page);
        if (!$found)
            return $this->pageNotFound();

        return $this->answer($this->documentation->editPage($found, $this->authentication->userId(), $request->validated('version'), $request->validated('edits')));
    }

    #[Endpoint('List documentation page versions', 'The versions of a page kept for 31 days, newest first, without their content : who wrote each one, and through which agent token. Read one with readDocPageVersion', 'listDocPageVersions')]
    #[ResourceResponse(DocPageVersionSummaryResource::class, isArray: true, description: 'The versions')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Page not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such page in the current workspace')]
    #[McpTool('list_doc_page_versions')]
    public function versions(Request $request, int $page): Response
    {
        $found = $this->documentation->findPage($this->workspace(), $page);

        return $found ? Response::json(DocPageVersionSummaryResource::of($this->documentation->versionsOf($found))) : $this->pageNotFound();
    }

    #[Endpoint('Read documentation page version', 'A past version of a page, with its whole Markdown content', 'readDocPageVersion')]
    #[ResourceResponse(DocPageVersionResource::class, description: 'The version')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Version not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such version in the current workspace')]
    #[McpTool('get_doc_page_version')]
    public function readVersion(Request $request, int $version): Response
    {
        $found = $this->documentation->findVersion($this->workspace(), $version);

        return $found
            ? Response::json(DocPageVersionResource::of($found))
            : Response::json(['error' => 'Version not found'], StatusCode::NOT_FOUND);
    }

    #[Endpoint('Upload documentation image', 'Stores an image for the documentation of the current workspace : PNG, JPEG, GIF or WebP, 5 MiB at most, its bytes encoded in base64. Put the markdown snippet of the answer in a page to show it', 'uploadDocImage')]
    #[ResourceResponse(DocImageResource::class, responseCode: StatusCode::CREATED, description: 'The image')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'The image must be a PNG, JPEG, GIF or WebP image'], responseCode: StatusCode::UNPROCESSABLE_CONTENT, description: 'Not base64, or not an accepted image')]
    #[RawResponse(['error' => 'The image weighs 6000000 bytes'], responseCode: StatusCode::CONTENT_TOO_LARGE, description: 'Over 5 MiB')]
    #[McpTool('upload_doc_image', write: true)]
    public function uploadImage(UploadDocImageRequest $request): Response
    {
        $image = $this->images->store($this->workspace(), $this->authentication->userId(), $request->validated('name'), $request->validated('content'));

        return $image instanceof DocumentationRefusal ? $this->refused($image) : Response::json(DocImageResource::of($image), StatusCode::CREATED);
    }

    #[Endpoint('Get documentation image', 'An image of the documentation, as referenced by the pages (/api/docs/images/{image})', 'getDocImage')]
    #[RawResponse(['error' => 'Authentication required'], responseCode: StatusCode::UNAUTHORIZED, description: 'No active session')]
    #[RawResponse(['error' => 'Image not found'], responseCode: StatusCode::NOT_FOUND, description: 'No such image in the current workspace')]
    #[McpTool('get_doc_image')]
    public function image(Request $request, int $image): Response
    {
        $found = $this->images->find($this->workspace(), $image);
        if (!$found)
            return Response::json(['error' => 'Image not found'], StatusCode::NOT_FOUND);

        return new Response(StatusCode::OK, $this->images->contentOf($found), [
            'Content-Type' => $found->type,
            // An image never changes : a new upload gets a new address
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    private function answer(DocPage|DocumentationRefusal $outcome): Response
    {
        return $outcome instanceof DocumentationRefusal ? $this->refused($outcome) : Response::json(DocPageResource::of($outcome));
    }

    private function refused(DocumentationRefusal $refusal): Response
    {
        return Response::json(['error' => $refusal->message], $refusal->status);
    }

    private function pageNotFound(): Response
    {
        return Response::json(['error' => 'Page not found'], StatusCode::NOT_FOUND);
    }

    private function workspace(): int
    {
        return $this->workspaces->current($this->authentication->userId())->id;
    }
}
