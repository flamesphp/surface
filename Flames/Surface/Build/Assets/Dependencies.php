<?php

declare(strict_types=1);

namespace Flames\Surface\Build\Assets;

/**
 * Resolves Flames/App class dependencies for the Surface client bundle.
 *
 * Uses static source parsing only — never loads PHP symbols during the build.
 *
 * @internal
 */
final class Dependencies
{
    /** @var callable(string): ?string */
    private $resolveClassFile;

    /** @param callable(string): ?string $resolveClassFile */
    public function __construct(callable $resolveClassFile)
    {
        $this->resolveClassFile = $resolveClassFile;
    }

    /**
     * @param list<class-string> $seeds
     *
     * @return list<class-string>
     */
    public function resolve(array $seeds): array
    {
        $visited = [];
        $order   = [];

        foreach ($seeds as $seed) {
            $this->walk($seed, $visited, $order);
        }

        return $order;
    }

    /**
     * Compile-time dependencies injected into Virtual::$dependencies at runtime.
     *
     * @return list<class-string>
     */
    public function directDependencies(string $class): array
    {
        return $this->readDependencies($class, false);
    }

    /**
     * Full dependency graph for bundle discovery (includes use imports).
     *
     * @return list<class-string>
     */
    public function bundleDependencies(string $class): array
    {
        return $this->readDependencies($class, true);
    }

    /**
     * @return list<class-string>
     */
    private function readDependencies(string $class, bool $includeImports): array
    {
        if (!$this->shouldBundle($class)) {
            return [];
        }

        $path = ($this->resolveClassFile)($class);
        if ($path === null) {
            return [];
        }

        $code = (string) @\file_get_contents($path);
        if ($code === '') {
            return [];
        }

        return $this->parseDependenciesFromSource($code, $includeImports);
    }

    /**
     * @param list<class-string> $seeds
     *
     * @return array<class-string, list<class-string>>
     */
    public function dependencyMap(array $seeds): array
    {
        $map      = [];
        $resolved = $this->resolve($seeds);

        foreach ($resolved as $class) {
            $map[$class] = $this->directDependencies($class);
        }

        return $map;
    }

    /**
     * @return list<class-string>
     */
    public static function scanClientReferences(string $namespacePrefix = 'Flames\\'): array
    {
        $clientPath = APP_PATH . 'Client/';
        if (!\is_dir($clientPath)) {
            return [];
        }

        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($clientPath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }

            $path = $item->getPathname();
            if (\str_contains($path, '/Client/Resource/Build/')) {
                continue;
            }

            $code = (string) @\file_get_contents($path);
            if ($code === '') {
                continue;
            }

            if (\preg_match_all('/\\\\Flames\\\\[A-Za-z0-9_\\\\]+/', $code, $matches)) {
                foreach ($matches[0] as $match) {
                    $class = \ltrim($match, '\\');
                    if (\str_starts_with($class, $namespacePrefix)) {
                        $found[$class] = true;
                    }
                }
            }

            if (\preg_match_all('/^\s*use\s+([^;]+);/m', $code, $useMatches)) {
                foreach ($useMatches[1] as $useLine) {
                    foreach (\preg_split('/\s*,\s*/', \trim($useLine)) as $import) {
                        $import = \preg_replace('/\s+as\s+\w+$/', '', \trim($import));
                        if (\str_starts_with($import, $namespacePrefix)) {
                            $found[$import] = true;
                        }
                    }
                }
            }
        }

        return \array_keys($found);
    }

    /**
     * @return list<class-string>
     */
    public static function scanClientClasses(): array
    {
        $clientPath = APP_PATH . 'Client/';
        if (!\is_dir($clientPath)) {
            return [];
        }

        $classes  = [];
        $rootPath = \strlen(ROOT_PATH);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($clientPath, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );

        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }

            $file = $item->getPathname();
            if (\str_contains($file, '/Client/Resource/Build/')) {
                continue;
            }

            $classes[] = \str_replace('/', '\\', \substr($file, $rootPath, -4));
        }

        \sort($classes);

        return $classes;
    }

    /**
     * @return list<class-string>
     */
    private function parseDependenciesFromSource(string $code, bool $includeImports = false): array
    {
        $namespace = '';
        if (\preg_match('/^\s*namespace\s+([^;]+);/m', $code, $match)) {
            $namespace = \trim($match[1]);
        }

        $imports = $this->parseUseImports($code);
        $resolve   = fn (string $name): string => $this->resolveTypeName($name, $namespace, $imports);
        $deps      = [];

        if (\preg_match('/\b(?:class|interface|trait|enum)\s+\w+\s+extends\s+([^\s{]+)/', $code, $match)) {
            $this->appendDependency($deps, $this->resolveExtendsType(\trim($match[1]), $resolve));
        }

        if (\preg_match('/\b(?:class|enum)\s+\w+\s+(?:extends\s+[^\s{]+\s+)?implements\s+([^{]+)/', $code, $match)) {
            foreach (\explode(',', $match[1]) as $interface) {
                $this->appendDependency($deps, $this->resolveExtendsType(\trim($interface), $resolve));
            }
        }

        // Trait imports inside class/trait bodies (indented, unqualified names only).
        if (\preg_match_all('/^\s+use\s+([^;\n\\\\]+);/m', $code, $matches)) {
            foreach ($matches[1] as $traits) {
                foreach (\explode(',', $traits) as $trait) {
                    $trait = \trim($trait);
                    if ($trait === '' || \str_starts_with($trait, 'function ') || \str_starts_with($trait, 'const ')) {
                        continue;
                    }
                    $this->appendDependency($deps, $resolve($trait));
                }
            }
        }

        if ($includeImports === true && \preg_match_all('/#\[([^\]]+)\]/', $code, $matches)) {
            foreach ($matches[1] as $attribute) {
                $name = \trim(\preg_split('/[(\s]/', $attribute)[0] ?? $attribute);
                $this->appendDependency($deps, $resolve($name));
            }
        }

        if ($includeImports === true) {
            foreach ($imports as $import) {
                $this->appendDependency($deps, $import);
            }

            $this->appendCompileTimeTypeReferences($code, $namespace, $imports, $deps);
        }

        return \array_values(\array_unique($deps));
    }

    /**
     * @return array<string, string>
     */
    private function parseUseImports(string $code): array
    {
        $imports = [];
        $header  = $this->fileLevelHeader($code);

        if (!\preg_match_all('/^\s*use\s+([^;]+);/m', $header, $matches)) {
            return $imports;
        }

        foreach ($matches[1] as $useLine) {
            foreach (\preg_split('/\s*,\s*/', \trim($useLine)) as $entry) {
                $entry = \trim($entry);
                if ($entry === '') {
                    continue;
                }

                if (\preg_match('/^(.+)\s+as\s+(\w+)$/', $entry, $aliasMatch)) {
                    $imports[$aliasMatch[2]] = \ltrim($aliasMatch[1], '\\');
                    continue;
                }

                $fqcn  = \ltrim($entry, '\\');
                $parts = \explode('\\', $fqcn);
                $imports[$parts[\count($parts) - 1]] = $fqcn;
            }
        }

        return $imports;
    }

    private function fileLevelHeader(string $code): string
    {
        if (\preg_match('/\b(class|interface|trait|enum)\s+/m', $code, $match, \PREG_OFFSET_CAPTURE)) {
            return \substr($code, 0, $match[0][1]);
        }

        return $code;
    }

    /**
     * @param callable(string): string $resolve
     */
    private function resolveExtendsType(string $type, callable $resolve): string
    {
        if (\str_starts_with($type, '\\')) {
            return \ltrim($type, '\\');
        }

        return $resolve($type);
    }

    /**
     * @param array<string, string> $imports
     */
    private function resolveTypeName(string $name, string $namespace, array $imports): string
    {
        $name = \ltrim($name, '\\');

        if ($name === '') {
            return $name;
        }

        if (isset($imports[$name])) {
            return $imports[$name];
        }

        if (\str_contains($name, '\\')) {
            $segments = \explode('\\', $name, 2);
            if (isset($imports[$segments[0]])) {
                return $imports[$segments[0]] . ($segments[1] !== '' ? '\\' . $segments[1] : '');
            }

            return $name;
        }

        if ($namespace !== '') {
            return $namespace . '\\' . $name;
        }

        return $name;
    }

    /**
     * @param array<string, string> $imports
     * @param list<class-string>    $deps
     */
    private function appendCompileTimeTypeReferences(
        string $code,
        string $namespace,
        array $imports,
        array &$deps,
    ): void {
        $builtIns = [
            'self' => true,
            'static' => true,
            'parent' => true,
            'mixed' => true,
            'array' => true,
            'callable' => true,
            'iterable' => true,
            'object' => true,
            'bool' => true,
            'int' => true,
            'float' => true,
            'string' => true,
            'void' => true,
            'never' => true,
            'null' => true,
        ];

        $expressions = [];

        if (\preg_match_all('/(?:\(|\)|,)\s*\??([A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:\[\])?)\s+\$/', $code, $matches)) {
            $expressions = \array_merge($expressions, $matches[1]);
        }

        if (\preg_match_all('/\b(?:private|protected|public)\s+(?:readonly\s+)*\??([A-Za-z_\\\\][A-Za-z0-9_\\\\]*(?:\[\])?)\s+\$/', $code, $matches)) {
            $expressions = \array_merge($expressions, $matches[1]);
        }

        if (\preg_match_all('/\)\s*:\s*\??([A-Za-z_\\\\][A-Za-z0-9_\\\\|\\\\]*)/', $code, $matches)) {
            $expressions = \array_merge($expressions, $matches[1]);
        }

        if (\preg_match_all('/(?<![\$\\w])(\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*)::/', $code, $matches)) {
            $expressions = \array_merge($expressions, $matches[1]);
        }

        foreach ($expressions as $expression) {
            foreach (\explode('|', $expression) as $type) {
                $type = \trim($type);
                if ($type === '' || isset($builtIns[\strtolower($type)])) {
                    continue;
                }

                if (\str_ends_with($type, '[]')) {
                    $type = \substr($type, 0, -2);
                }

                if (\str_starts_with($type, '\\')) {
                    $this->appendDependency($deps, \ltrim($type, '\\'));
                    continue;
                }

                $this->appendDependency($deps, $this->resolveTypeName($type, $namespace, $imports));
            }
        }
    }

    /**
     * @param list<class-string> $deps
     */
    private function appendDependency(array &$deps, string $class): void
    {
        if (!$this->shouldBundle($class)) {
            return;
        }

        if (!preg_match('/^(Flames|App)(\\\\[A-Za-z_][A-Za-z0-9_]*)+$/', $class)) {
            return;
        }

        $deps[] = $class;
    }

    /**
     * @param array<class-string, true> $visited
     * @param list<class-string>        $order
     */
    private function walk(string $class, array &$visited, array &$order): void
    {
        if (isset($visited[$class])) {
            return;
        }

        if (!$this->shouldBundle($class)) {
            return;
        }

        if (($this->resolveClassFile)($class) === null) {
            return;
        }

        $visited[$class] = true;

        foreach ($this->bundleDependencies($class) as $dependency) {
            $this->walk($dependency, $visited, $order);
        }

        $order[] = $class;
    }

    private function shouldBundle(string $class): bool
    {
        if ($class === '') {
            return false;
        }

        return \str_starts_with($class, 'Flames\\') || \str_starts_with($class, 'App\\');
    }
}
