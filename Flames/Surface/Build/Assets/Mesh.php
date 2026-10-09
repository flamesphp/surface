<?php

declare(strict_types=1);

namespace Flames\Surface\Build\Assets;

use Flames\Env\Env;

/**
 * Bundles {@see \Flames\Mesh} runtime classes for client-side view rendering.
 *
 * @internal
 */
class Mesh
{
    /** Extensions watched for auto-rebuild under App/Client/ (SplFileInfo suffix). */
    public const VIEW_WATCH_EXTENSIONS = ['.mesh', '.twig', '.html'];

    /** Style/script assets watched under App/Client/ (unchanged legacy set). */
    public const STYLE_WATCH_EXTENSIONS = ['.css', '.scss', '.sass'];

    /** @var list<class-string>|null */
    private static ?array $defaultFiles = null;

    /** @var list<class-string> */
    protected static array $clientMocks = [];

    public static function isMeshExtension(): bool
    {
        $enabled = Env::get('CLIENT_MESH_ENABLED');
        if ($enabled !== null) {
            return $enabled === true;
        }

        return Env::get('CLIENT_TEMPLATE_ENABLED') === true;
    }

    /** @deprecated use isMeshExtension() */
    public static function isTemplateExtension(): bool
    {
        return self::isMeshExtension();
    }

    public static function isClientViewFile(string $path): bool
    {
        $lower = strtolower(str_replace('\\', '/', $path));

        if (str_ends_with($lower, '.html.twig') || str_ends_with($lower, '.mesh') || str_ends_with($lower, '.twig')) {
            return true;
        }

        return str_ends_with($lower, '.html');
    }

    public static function isPlainHtmlView(string $path): bool
    {
        $lower = strtolower(str_replace('\\', '/', $path));

        return str_ends_with($lower, '.html')
            && !str_ends_with($lower, '.html.twig');
    }

    public static function shouldBundleClientView(string $path, string $contents): bool
    {
        if (self::isPlainHtmlView($path)) {
            return true;
        }

        $contents = (string)preg_replace('/ {2,}/', ' ', $contents);

        return str_starts_with($contents, '{% export true %}');
    }

    /**
     * @return list<class-string>
     */
    public static function defaultFiles(): array
    {
        if (self::$defaultFiles !== null) {
            return self::$defaultFiles;
        }

        $classes = [\Flames\Mesh::class];
        $root    = FLAMES_PATH . 'mesh/Flames/Mesh/';

        if (!is_dir($root)) {
            self::$defaultFiles = $classes;
            return $classes;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || strtolower($item->getExtension()) !== 'php') {
                continue;
            }

            $relative = substr($item->getPathname(), strlen($root));
            $classes[] = 'Flames\\Mesh\\' . str_replace(['/', '\\'], '\\', substr($relative, 0, -4));
        }

        self::$defaultFiles = array_values(array_unique($classes));

        return self::$defaultFiles;
    }

    /** @param list<class-string> $defaultFiles */
    public static function injectDefaultFiles(array $defaultFiles): array
    {
        return array_merge($defaultFiles, self::defaultFiles());
    }

    /** @param list<class-string> $clientMocks */
    public static function injectClientMocks(array $clientMocks): array
    {
        return array_merge($clientMocks, self::$clientMocks);
    }
}
