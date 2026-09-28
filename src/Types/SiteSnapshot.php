<?php

namespace Whilesmart\WebSearch\Types;

final class SiteSnapshot
{
    /**
     * @param  array<string, string>  $meta
     * @param  array<string, string>  $openGraph
     * @param  list<array{src: string, alt: string}>  $images
     * @param  list<string>  $videos
     * @param  ?string  $screenshot  base64 PNG
     */
    public function __construct(
        public readonly string $url,
        public readonly string $finalUrl,
        public readonly ?int $statusCode,
        public readonly string $title,
        public readonly string $description,
        public readonly string $html,
        public readonly string $markdown,
        public readonly array $meta,
        public readonly array $openGraph,
        public readonly array $images,
        public readonly array $videos,
        public readonly ?string $favicon,
        public readonly ?string $screenshot,
        public readonly string $fetcher,
    ) {}

    public function image(): ?string
    {
        return $this->openGraph['og:image'] ?? $this->openGraph['twitter:image'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'final_url' => $this->finalUrl,
            'status_code' => $this->statusCode,
            'title' => $this->title,
            'description' => $this->description,
            'html' => $this->html,
            'markdown' => $this->markdown,
            'meta' => $this->meta,
            'open_graph' => $this->openGraph,
            'images' => $this->images,
            'videos' => $this->videos,
            'favicon' => $this->favicon,
            'screenshot' => $this->screenshot,
            'fetcher' => $this->fetcher,
        ];
    }
}
