<?php

namespace App\Documentation;

use App\Models\DocPage;
use App\Models\DocPageVersion;
use App\Models\Workspace;
use App\Services\HistoryActor;
use App\Services\HistoryConfiguration;
use App\Services\HistoryService;
use Cube\Data\Bunch;
use Cube\Data\Database\Database;

class DocumentationService
{
    /** A TEXT column holds 64 KiB */
    public const MAX_CONTENT_BYTES = 65535;
    public const MAX_TAGS = 20;
    public const MAX_TAG_LENGTH = 40;

    public function __construct(
        protected HistoryService $history,
    ) {}

    /**
     * @return Bunch<int,DocPage> Sorted by title, with their last author
     */
    public function listPages(int $workspace, ?string $search = null, ?string $tag = null, bool $includeArchived = false): Bunch
    {
        $query = DocPage::select(['_updated_by'])->where('workspace', $workspace);
        if (!$includeArchived)
            $query->whereRaw('archived_at IS NULL');
        if (null !== $search && '' !== $search)
            $query->whereGroup(fn ($group) => $group
                ->where('title', "%{$search}%", 'LIKE')
                ->or()->where('content', "%{$search}%", 'LIKE'));

        return Bunch::wrap($query->order('title', 'ASC')->order('id', 'ASC')->fetch())
            ->filter(fn (DocPage $page) => null === $tag || in_array($tag, self::tagsOf($page), true), keepValues: true);
    }

    public function findPage(int $workspace, int $id): ?DocPage
    {
        $page = DocPage::select(['_updated_by'])->where('id', $id)->first();

        return $page && $page->workspace === $workspace ? $page : null;
    }

    /**
     * @param list<string> $tags
     */
    public function createPage(int $workspace, int $user, string $title, string $content, ?int $parent, array $tags): DocPage|DocumentationRefusal
    {
        if (null !== $parent && !$this->findPage($workspace, $parent))
            return DocumentationRefusal::invalidParent($parent);

        $now = date('Y-m-d H:i:s');
        $page = (new DocPage([
            'workspace' => $workspace,
            'parent' => $parent,
            'title' => $title,
            'content' => $content,
            'tags' => $this->encodeTags($tags),
            'version' => 1,
            'created_by' => $user,
            'updated_by' => $user,
            'created_at' => $now,
            'updated_at' => $now,
        ]))->save();

        $this->saveVersion($page, $user);
        $this->log($page, 'doc.page.created', $user);

        return $this->findPage($workspace, $page->id);
    }

    /**
     * A change of title, content or tags makes a new version. A move or an archiving does not.
     * Archiving or restoring a page does the same to all its sub-pages.
     *
     * @param ?int         $expectedVersion the version the change was made from : null overwrites whatever is there
     * @param list<string> $tags
     */
    public function updatePage(DocPage $page, int $user, ?int $expectedVersion, string $title, string $content, array $tags, ?int $parent, bool $archived): DocPage|DocumentationRefusal
    {
        if (null !== $expectedVersion && $expectedVersion !== $page->version)
            return DocumentationRefusal::conflict($expectedVersion, $page->version);
        if ($parent !== $page->parent && null !== $parent && !$this->canBeParent($page, $parent))
            return DocumentationRefusal::invalidParent($parent);

        $encodedTags = $this->encodeTags($tags);
        $rewritten = $title !== $page->title || $content !== $page->content || $encodedTags !== $page->tags;
        $moved = $parent !== $page->parent;
        $archiving = $archived !== (null !== $page->archived_at);
        if (!$rewritten && !$moved && !$archiving)
            return $page;

        $previousTitle = $page->title;
        $page->title = $title;
        $page->content = $content;
        $page->tags = $encodedTags;
        $page->parent = $parent;
        if ($archiving)
            $page->archived_at = $archived ? date('Y-m-d H:i:s') : null;
        $page->updated_by = $user;
        $page->updated_at = date('Y-m-d H:i:s');
        if ($rewritten)
            ++$page->version;
        $page->save();

        if ($rewritten) {
            $this->saveVersion($page, $user);
            $this->log($page, 'doc.page.updated', $user, $previousTitle !== $title ? ['previous_title' => $previousTitle] : []);
        }
        if ($moved)
            $this->log($page, 'doc.page.moved', $user, ['parent' => null === $parent ? null : DocPage::find($parent)?->title]);
        if ($archiving)
            $this->log($page, $archived ? 'doc.page.archived' : 'doc.page.restored', $user, ['sub_pages' => $this->archiveBelow($page, $user, $archived)]);

        return $this->findPage($page->workspace, $page->id);
    }

    /**
     * @return int The number of sub-pages that changed state
     */
    private function archiveBelow(DocPage $page, int $user, bool $archived): int
    {
        $below = array_slice($this->branchOf($page), 1);
        if (!$below)
            return 0;

        $changing = DocPage::select()->whereIn('id', $below)->whereRaw($archived ? 'archived_at IS NULL' : 'archived_at IS NOT NULL')->fetch();
        if (!$changing)
            return 0;

        $now = date('Y-m-d H:i:s');
        DocPage::update()
            ->set('archived_at', $archived ? $now : null)
            ->set('updated_by', $user)
            ->set('updated_at', $now)
            ->whereIn('id', array_map(fn (DocPage $child) => $child->id, $changing))
            ->fetch();

        return count($changing);
    }

    /**
     * Replaces passages of the content, each one found exactly once, all or none.
     *
     * @param list<array{old_text: string, new_text: string}> $edits
     */
    public function editPage(DocPage $page, int $user, ?int $expectedVersion, array $edits): DocPage|DocumentationRefusal
    {
        $content = $page->content;
        foreach ($edits as $index => ['old_text' => $old, 'new_text' => $new]) {
            $matches = substr_count($content, $old);
            if (1 !== $matches)
                return DocumentationRefusal::editNotFound($index, $matches);

            $content = str_replace($old, $new, $content);
        }

        if (strlen($content) > self::MAX_CONTENT_BYTES)
            return DocumentationRefusal::contentTooLong(strlen($content));

        return $this->updatePage($page, $user, $expectedVersion, $page->title, $content, self::tagsOf($page), $page->parent, null !== $page->archived_at);
    }

    /**
     * Deletes the page with its sub-pages, whoever wrote them, and their versions.
     */
    public function deletePage(DocPage $page, int $user): ?DocumentationRefusal
    {
        if (!$this->canDelete($page, $user))
            return DocumentationRefusal::cannotDelete($page->id);

        $branch = $this->branchOf($page);
        $failure = Database::getInstance()->transaction(fn () => $this->deletePages($branch));
        if ($failure)
            throw $failure;

        $this->history->log($page->workspace, 'doc.page.deleted', ['title' => $page->title, 'page' => $page->id, 'sub_pages' => count($branch) - 1], user: $user);

        return null;
    }

    /**
     * The member who created the page, or the author of its workspace.
     */
    public function canDelete(DocPage $page, int $user): bool
    {
        return $page->created_by === $user || Workspace::find($page->workspace)->author === $user;
    }

    /**
     * Inside the transaction of the caller.
     */
    public function deleteWorkspacePages(int $workspace): void
    {
        $this->deletePages(array_map(fn (DocPage $page) => $page->id, DocPage::select()->where('workspace', $workspace)->fetch()));
    }

    /**
     * @return Bunch<int,DocPageVersion> Newest first, with their author
     */
    public function versionsOf(DocPage $page): Bunch
    {
        return Bunch::wrap(DocPageVersion::select(['_user'])->where('page', $page->id)->order('version', 'DESC')->fetch());
    }

    public function findVersion(int $workspace, int $id): ?DocPageVersion
    {
        $version = DocPageVersion::select(['_user'])->where('id', $id)->first();

        return $version && $this->findPage($workspace, $version->page) ? $version : null;
    }

    /**
     * The version a page is at is kept whatever its age : the history pane always shows who wrote it.
     */
    public function purgeExpiredVersions(HistoryConfiguration $configuration): void
    {
        Database::getInstance()->exec(
            'DELETE FROM doc_page_version WHERE created_at < {}
                AND NOT EXISTS (SELECT 1 FROM doc_page WHERE doc_page.id = doc_page_version.page AND doc_page.version = doc_page_version.version)',
            [date('Y-m-d H:i:s', strtotime("-{$configuration->retentionDays} days"))]
        );
    }

    /**
     * @return list<string>
     */
    public static function tagsOf(DocPage|DocPageVersion $page): array
    {
        return json_decode($page->tags, true);
    }

    /**
     * @return list<int> The page and every page below it
     */
    private function branchOf(DocPage $page): array
    {
        $branch = [$page->id];
        $level = [$page->id];
        while ($level) {
            $level = array_map(fn (DocPage $child) => $child->id, DocPage::select()->whereIn('parent', $level)->fetch());
            array_push($branch, ...$level);
        }

        return $branch;
    }

    /**
     * @param list<int> $pages
     */
    private function deletePages(array $pages): void
    {
        if (!$pages)
            return;

        // The pages reference each other : MySQL checks the key row by row, in no given order
        DocPage::update()->set('parent', null)->whereIn('id', $pages)->fetch();
        DocPageVersion::delete()->whereIn('page', $pages)->fetch();
        DocPage::delete()->whereIn('id', $pages)->fetch();
    }

    private function canBeParent(DocPage $page, int $parent): bool
    {
        $ancestor = $this->findPage($page->workspace, $parent);
        while ($ancestor) {
            if ($ancestor->id === $page->id)
                return false;
            if (null === $ancestor->parent)
                return true;

            $ancestor = DocPage::find($ancestor->parent);
        }

        return false;
    }

    private function saveVersion(DocPage $page, int $user): void
    {
        (new DocPageVersion([
            'page' => $page->id,
            'version' => $page->version,
            'title' => $page->title,
            'content' => $page->content,
            'tags' => $page->tags,
            'user' => $user,
            'token' => HistoryActor::getInstance()->tokenName,
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();
    }

    /**
     * @param list<string> $tags
     */
    private function encodeTags(array $tags): string
    {
        $tags = array_values(array_unique(array_filter(array_map('trim', $tags), fn (string $tag) => '' !== $tag)));
        sort($tags);

        return json_encode($tags, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function log(DocPage $page, string $type, int $user, array $details = []): void
    {
        $this->history->log($page->workspace, $type, ['title' => $page->title, 'page' => $page->id, 'version' => $page->version, ...$details], user: $user);
    }
}
