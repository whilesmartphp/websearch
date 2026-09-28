<?php

namespace Whilesmart\WebSearch\Support;

final class Url
{
    public static function resolve(string $base, string $reference): string
    {
        $reference = trim($reference);

        if ($reference === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $reference)) {
            return $reference;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme.'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($reference, '//')) {
            return $scheme.':'.$reference;
        }

        if (str_starts_with($reference, '/')) {
            return $origin.self::normalise($reference);
        }

        if (str_starts_with($reference, '?') || str_starts_with($reference, '#')) {
            return $origin.($parts['path'] ?? '/').$reference;
        }

        $directory = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin.self::normalise($directory.$reference);
    }

    private static function normalise(string $path): string
    {
        [$path, $suffix] = array_pad(preg_split('/(?=[?#])/', $path, 2) ?: [$path], 2, '');
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        $normalised = implode('/', $segments);

        return (str_starts_with($normalised, '/') ? $normalised : '/'.$normalised).$suffix;
    }
}
