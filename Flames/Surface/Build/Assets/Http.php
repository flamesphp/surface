<?php

declare(strict_types=1);

namespace Flames\Surface\Build\Assets;

/**
 * Bundles {@see \Flames\Http} runtime classes for the client-side HTTP client.
 *
 * @internal
 */
final class Http
{
    /** @var list<class-string>|null */
    private static ?array $defaultFiles = null;

    public static function isHttpExtension(): bool
    {
        return self::clientUsesHttp();
    }

    public static function shouldBundle(): bool
    {
        return self::clientUsesHttp();
    }

    public static function clientUsesHttp(): bool
    {
        return array_any(Dependencies::scanClientReferences('Flames\\Http\\'), fn($class) => \str_starts_with($class, 'Flames\\Http\\'));
    }

    /**
     * @param list<class-string> $defaultFiles
     *
     * @return list<class-string>
     */
    public static function injectDefaultFiles(array $defaultFiles): array
    {
        if (!self::isHttpExtension()) {
            return $defaultFiles;
        }

        return \array_values(\array_unique(\array_merge($defaultFiles, self::defaultFiles())));
    }

    /**
     * @return list<class-string>
     */
    public static function defaultFiles(): array
    {
        if (self::$defaultFiles !== null) {
            return self::$defaultFiles;
        }

        $root = FLAMES_PATH . 'http/Flames/Http/';
        if (!\is_dir($root)) {
            return self::$defaultFiles = [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || !\str_ends_with($item->getFilename(), '.php')) {
                continue;
            }

            $path = $item->getPathname();
            if (self::shouldSkip($path)) {
                continue;
            }

            $relative = \substr($path, \strlen($root));
            $class    = 'Flames\\Http\\' . \str_replace('/', '\\', \substr($relative, 0, -4));
            $files[]  = $class;
        }

        \sort($files);

        return self::$defaultFiles = $files;
    }

    private static function shouldSkip(string $path): bool
    {
        $normalized = \str_replace('\\', '/', $path);
        return array_any([
            '/Handler/Curl',
            '/Handler/StreamHandler.php',
            '/Handler/EasyHandle.php',
            '/Async/Request/Client.php',
            '/Async/Response/Client.php',
        ], fn($pattern) => \str_contains($normalized, $pattern));
    }
}
