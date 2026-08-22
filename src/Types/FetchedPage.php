<?php

namespace Whilesmart\WebSearch\Types;

final class FetchedPage
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $markdown,
        public readonly string $fetcher,
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'markdown' => $this->markdown,
            'fetcher' => $this->fetcher,
        ];
    }
}
