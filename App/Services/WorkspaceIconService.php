<?php

namespace App\Services;

use App\Favicons\Favicon;
use App\Models\Workspace;
use Cube\Env\Storage;
use Cube\Web\Http\Upload;

/**
 * The icon of a workspace : an image uploaded by a member, else the logo of Tower in the workspace color.
 */
class WorkspaceIconService
{
    public const MAX_SIZE = Upload::MB;

    /** Detected type => extension of the stored file */
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    // The random part of the file name is all that guards its public address : 64 bits before, 128 since
    private const FILE_NAME = '/^\d+-(?:[0-9a-f]{16}|[0-9a-f]{32})\.(?:png|jpg)$/';

    /** The color of Tower, for a workspace without one : keep it in sync with Frontend/src/components/TowerLogo.vue */
    public const DEFAULT_COLOR = '#2190FF';

    /** Same drawing as Frontend/public/favicon.svg, `{color}` standing for its accent */
    private const LOGO = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512">
          <rect width="512" height="512" rx="112" fill="{color}"/>
          <g fill="#FFFFFF">
            <rect x="128" y="108" width="52" height="70" rx="12"/>
            <rect x="230" y="108" width="52" height="70" rx="12"/>
            <rect x="332" y="108" width="52" height="70" rx="12"/>
            <rect x="128" y="150" width="256" height="63.752" rx="16"/>
            <rect x="188" y="186" width="136" height="228" rx="18.133"/>
          </g>
          <g fill="{color}">
            <rect x="246.933" y="256" width="18.133" height="44" rx="9.067" ry="8"/>
            <path d="M224.267 414 V370 a31.733 28 0 0 1 63.467 0 V414 Z"/>
          </g>
        </svg>
        SVG;

    /**
     * @return ?string The type read from the content, `null` when it is not a PNG or a JPEG image
     */
    public function typeOf(string $content): ?string
    {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!isset(self::TYPES[$type]))
            return null;

        // A valid signature followed by anything else is not an image
        return false === @getimagesizefromstring($content) ? null : $type;
    }

    /**
     * @param string $type one of the keys of TYPES, as given by typeOf()
     */
    public function set(Workspace $workspace, string $content, string $type): Workspace
    {
        $name = $workspace->id.'-'.bin2hex(random_bytes(16)).'.'.self::TYPES[$type];
        if (!$this->storage()->write($name, $content))
            throw new \RuntimeException("Cannot write the icon of workspace {$workspace->id}");

        $this->deleteFile($workspace);
        $workspace->icon = $name;

        return $workspace->save();
    }

    public function remove(Workspace $workspace): Workspace
    {
        $this->deleteFile($workspace);
        $workspace->icon = null;

        return $workspace->save();
    }

    /**
     * Removes the stored image only, the row is left as is : for a workspace being deleted.
     */
    public function deleteFile(Workspace $workspace): void
    {
        if (null !== $workspace->icon && $this->storage()->isFile($workspace->icon))
            $this->storage()->unlink($workspace->icon);
    }

    public function of(Workspace $workspace): Favicon
    {
        $storage = $this->storage();
        if (null !== $workspace->icon && $storage->isFile($workspace->icon)) {
            $type = array_search(pathinfo($workspace->icon, PATHINFO_EXTENSION), self::TYPES, true);

            return new Favicon($type ?: 'application/octet-stream', $storage->read($workspace->icon));
        }

        return new Favicon('image/svg+xml', $this->logo($workspace->accent_color ?? self::DEFAULT_COLOR));
    }

    public function publicPath(Workspace $workspace): ?string
    {
        return null === $workspace->icon ? null : '/api/workspace-icons/'.rawurlencode($workspace->icon);
    }

    public function ofPublicFile(string $file): ?Favicon
    {
        if (1 !== preg_match(self::FILE_NAME, $file) || !$workspace = Workspace::findWhere(['icon' => $file]))
            return null;

        return $this->of($workspace);
    }

    /**
     * Changes whenever of() would answer something else : it busts the browser cache of the icon.
     */
    public function version(Workspace $workspace): string
    {
        return $workspace->icon
            ? pathinfo($workspace->icon, PATHINFO_FILENAME)
            : ltrim($workspace->accent_color ?? self::DEFAULT_COLOR, '#');
    }

    private function logo(string $color): string
    {
        return str_replace('{color}', htmlspecialchars($color, ENT_XML1 | ENT_QUOTES), self::LOGO)."\n";
    }

    private function storage(): Storage
    {
        return Storage::getInstance()->child('WorkspaceIcons');
    }
}
