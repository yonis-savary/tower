<?php

namespace App\Search;

final class SearchResult
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $detail,
        public readonly ?string $url,
        public readonly bool $done,
    ) {}

    /**
     * @return array{type: string, id: int, title: string, detail: ?string, url: ?string, done: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'title' => $this->title,
            'detail' => $this->detail,
            'url' => $this->url,
            'done' => $this->done,
        ];
    }
}
