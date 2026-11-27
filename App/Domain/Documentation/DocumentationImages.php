<?php

namespace App\Domain\Documentation;

use App\Models\DocImage;
use Cube\Env\Storage;
use Cube\Web\Http\Upload;

class DocumentationImages
{
    public const MAX_SIZE = 5 * Upload::MB;

    /** Detected type => extension of the stored file. No SVG : it can carry scripts */
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    public function store(int $workspace, int $user, string $name, string $base64): DocImage|DocumentationRefusal
    {
        $content = base64_decode($base64, true);
        if (false === $content)
            return DocumentationRefusal::notAnImage();
        if (strlen($content) > self::MAX_SIZE)
            return DocumentationRefusal::imageTooLarge(strlen($content));

        $type = $this->typeOf($content);
        if (null === $type)
            return DocumentationRefusal::notAnImage();

        $file = $workspace.'-'.bin2hex(random_bytes(12)).'.'.self::TYPES[$type];
        if (!$this->storage()->write($file, $content))
            throw new \RuntimeException("Cannot write the documentation image {$file} of workspace {$workspace}");

        return (new DocImage([
            'workspace' => $workspace,
            'name' => $name,
            'type' => $type,
            'size' => strlen($content),
            'file' => $file,
            'user' => $user,
            'created_at' => date('Y-m-d H:i:s'),
        ]))->save();
    }

    public function find(int $workspace, int $id): ?DocImage
    {
        $image = DocImage::find($id);

        return $image && $image->workspace === $workspace ? $image : null;
    }

    public function contentOf(DocImage $image): string
    {
        return $this->storage()->read($image->file);
    }

    /**
     * @return list<string>
     */
    public function filesOf(int $workspace): array
    {
        return array_map(fn (DocImage $image) => $image->file, DocImage::select()->where('workspace', $workspace)->fetch());
    }

    /**
     * @param list<string> $files
     */
    public function deleteFiles(array $files): void
    {
        foreach ($files as $file) {
            if ($this->storage()->isFile($file))
                $this->storage()->unlink($file);
        }
    }

    public static function urlOf(DocImage $image): string
    {
        return "/api/docs/images/{$image->id}";
    }

    private function typeOf(string $content): ?string
    {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if (!isset(self::TYPES[$type]))
            return null;

        // A valid signature followed by anything else is not an image
        return false === @getimagesizefromstring($content) ? null : $type;
    }

    private function storage(): Storage
    {
        return Storage::getInstance()->child('DocImages');
    }
}
