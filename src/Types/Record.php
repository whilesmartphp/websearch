<?php

namespace Whilesmart\WebSearch\Types;

use DateTimeImmutable;

final class Record
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $url,
        public readonly string $source,
        public readonly ?DateTimeImmutable $publishedAt = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly array $attributes = [],
    ) {}

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < ($now ?? new DateTimeImmutable);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'source' => $this->source,
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'attributes' => $this->attributes,
        ];
    }
}
