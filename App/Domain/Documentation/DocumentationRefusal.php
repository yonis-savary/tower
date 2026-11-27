<?php

namespace App\Domain\Documentation;

use Cube\Web\Http\StatusCode;

final class DocumentationRefusal
{
    private function __construct(
        public readonly int $status,
        public readonly string $message,
    ) {}

    public static function invalidParent(int $parent): self
    {
        return new self(StatusCode::UNPROCESSABLE_CONTENT, "Page {$parent} cannot be the parent : it is unknown in this workspace, or it is the page itself or one of its sub-pages");
    }

    public static function conflict(int $expected, int $current): self
    {
        return new self(StatusCode::CONFLICT, "The page changed since version {$expected} : it is at version {$current}, read it again before changing it");
    }

    public static function editNotFound(int $index, int $matches): self
    {
        return new self(StatusCode::UNPROCESSABLE_CONTENT, "edits.{$index}.old_text must appear exactly once in the content, found {$matches} times");
    }

    public static function contentTooLong(int $bytes): self
    {
        return new self(StatusCode::UNPROCESSABLE_CONTENT, "The content would weigh {$bytes} bytes, ".DocumentationService::MAX_CONTENT_BYTES.' at most');
    }

    public static function cannotDelete(int $page): self
    {
        return new self(StatusCode::FORBIDDEN, "Only the member who created page {$page}, or the author of the workspace, can delete it");
    }

    /**
     * @param list<int> $subPages
     */
    public static function cannotDeleteSubPages(int $page, array $subPages): self
    {
        return new self(StatusCode::FORBIDDEN, "Page {$page} holds pages created by other members, which only they or the author of the workspace can delete : ".implode(', ', $subPages));
    }

    public static function notAnImage(): self
    {
        return new self(StatusCode::UNPROCESSABLE_CONTENT, 'The image must be a PNG, JPEG, GIF or WebP image, encoded in base64');
    }

    public static function imageTooLarge(int $bytes): self
    {
        return new self(StatusCode::CONTENT_TOO_LARGE, "The image weighs {$bytes} bytes, ".DocumentationImages::MAX_SIZE.' at most');
    }
}
