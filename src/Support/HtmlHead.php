<?php

namespace Whilesmart\WebSearch\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class HtmlHead
{
    private DOMXPath $xpath;

    public function __construct(string $html, private readonly string $baseUrl)
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($document);
    }

    public function title(): string
    {
        return trim((string) $this->xpath->evaluate('string(//head/title)'));
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>} meta, then og:* and twitter:*
     */
    public function meta(): array
    {
        $meta = [];
        $social = [];

        foreach ($this->xpath->query('//meta[@content]') ?: [] as $node) {
            /** @var DOMElement $node */
            $key = mb_strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name')));
            $content = trim($node->getAttribute('content'));

            if ($key === '' || $content === '') {
                continue;
            }

            if (str_starts_with($key, 'og:') || str_starts_with($key, 'twitter:')) {
                $social[$key] ??= $this->absoluteIfMedia($key, $content);
            } else {
                $meta[$key] ??= $content;
            }
        }

        return [$meta, $social];
    }

    public function favicon(): string
    {
        $best = null;
        $bestScore = -1;

        foreach ($this->xpath->query('//link[@href]') ?: [] as $node) {
            /** @var DOMElement $node */
            $rel = ' '.mb_strtolower($node->getAttribute('rel')).' ';

            if (! str_contains($rel, ' icon ') && ! str_contains($rel, ' apple-touch-icon ')) {
                continue;
            }

            $score = str_contains($node->getAttribute('type'), 'svg') ? 10000 : (int) $node->getAttribute('sizes');

            if ($score > $bestScore) {
                $best = $node->getAttribute('href');
                $bestScore = $score;
            }
        }

        return Url::resolve($this->baseUrl, $best ?? '/favicon.ico');
    }

    /**
     * @return list<array{src: string, alt: string}>
     */
    public function images(int $limit = 50): array
    {
        $images = [];

        foreach ($this->xpath->query('//img[@src]') ?: [] as $node) {
            /** @var DOMElement $node */
            $src = trim($node->getAttribute('src'));

            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $images[] = ['src' => Url::resolve($this->baseUrl, $src), 'alt' => trim($node->getAttribute('alt'))];

            if (count($images) >= $limit) {
                break;
            }
        }

        return $images;
    }

    /**
     * @return list<string>
     */
    public function videos(): array
    {
        $videos = [];

        foreach ($this->xpath->query('//video[@src] | //video/source[@src]') ?: [] as $node) {
            /** @var DOMElement $node */
            $videos[] = Url::resolve($this->baseUrl, $node->getAttribute('src'));
        }

        return array_values(array_unique($videos));
    }

    private function absoluteIfMedia(string $key, string $content): string
    {
        return in_array($key, ['og:image', 'og:image:url', 'og:image:secure_url', 'og:video', 'og:url', 'twitter:image'], true)
            ? Url::resolve($this->baseUrl, $content)
            : $content;
    }
}
