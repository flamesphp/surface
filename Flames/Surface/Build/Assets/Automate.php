<?php

declare(strict_types=1);

namespace Flames\Surface\Build\Assets;

use Flames\Env\Env;

/**
 * Computes a hash of all client-side files to detect changes for auto-build.
 *
 * Watches every file under App/Client/ (except Resource/Build/ output).
 *
 * @internal
 */
final class Automate
{
    private bool $debug = false;

    /** @var list<string>|null */
    private ?array $ignorePaths = null;

    /** @var list<array{path:string,changed:int,type:string}>|null */
    private ?array $files = null;

    public function run(bool $debug = false): bool
    {
        $this->debug = $debug;

        if ($this->debug) {
            echo 'Current modified hash: ' . $this->getCurrentHash();
        }

        return true;
    }

    public function getCurrentHash(): string
    {
        $ignorePath = Env::get('AUTO_BUILD_IGNORE_PATHS');
        if ($ignorePath !== null) {
            $parts = explode(',', (string)$ignorePath);
            if (!empty($parts)) {
                $this->ignorePaths = $parts;
            }
        }

        $this->buildFileTimes();
        return sha1(serialize($this->files));
    }

    private function buildFileTimes(): void
    {
        $this->files = [];

        $envFile = ROOT_PATH . '.env';
        if (file_exists($envFile)) {
            $this->files[] = [
                'path'    => $envFile,
                'changed' => filemtime($envFile),
                'type'    => 'config',
            ];
        }

        $clientPath = APP_PATH . 'Client/';
        $exclude    = [APP_PATH . 'Client/Resource/Build/'];

        $this->collectFiles($clientPath, 'client', ['.php'], $exclude);
        $this->collectFiles($clientPath, 'resource', ['.js'], $exclude);
        $this->collectFiles($clientPath, 'view', Mesh::VIEW_WATCH_EXTENSIONS, $exclude);
        $this->collectFiles($clientPath, 'public', array_merge(Mesh::STYLE_WATCH_EXTENSIONS, ['.js']), $exclude);
    }

    /**
     * @param list<string> $extensions  Lowercase extensions to accept (with dot).
     * @param list<string> $excludeDirs Absolute path prefixes to skip.
     */
    private function collectFiles(string $dir, string $type, array $extensions, array $excludeDirs = []): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir()) {
                continue;
            }

            $path = $item->getPathname();

            foreach ($excludeDirs as $excl) {
                if (str_starts_with($path, $excl)) {
                    continue 2;
                }
            }

            if ($this->ignorePaths !== null && $this->isIgnored($path)) {
                continue;
            }

            $ext = strtolower('.' . $item->getExtension());
            if (!in_array($ext, $extensions, true)) {
                continue;
            }

            $this->files[] = [
                'path'    => $path,
                'changed' => $item->getMTime(),
                'type'    => $type,
            ];
        }
    }

    private function isIgnored(string $path): bool
    {
        foreach ($this->ignorePaths as $ignored) {
            if (str_starts_with($path, ROOT_PATH . $ignored)) {
                return true;
            }
        }
        return false;
    }
}
