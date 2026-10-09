<?php

declare(strict_types=1);

namespace Flames\Surface\Runtime;

/**
 * Virtual class loader for Surface client-side precompiled PHP.
 *
 * @internal
 */
final class Virtual
{
    /** @var array<string, string> */
    private static array $buffers = [];

    /** @var array<string, list<string>> */
    private static array $dependencies = [];

    /** @var array<string, true> */
    private static array $loaded = [];

    /** @var array<string, true> */
    private static array $loading = [];

    /** @var list<string> */
    private static array $constructors = [];

    /** @var array<string, string> */
    private static array $tags = [];

    /** @var array<string, string> */
    private static array $views = [];

    /** @var list<class-string> Loaded directly before bootstrap — excluded from lazy buffers. */
    private const array BOOTSTRAP_CLASSES = [
        self::class,
        AutoLoad::class,
    ];

    /**
     * Hydrates static state from the decoded Surface payload (set on window.Flames.Internal).
     *
     * @param object $payload Decoded Surface payload manifest
     */
    public static function bootstrap(object $payload): void
    {
        self::$buffers      = (array) ($payload->buffers ?? []);
        self::$dependencies = (array) ($payload->dependencies ?? []);
        self::$constructors = array_values((array) ($payload->constructors ?? []));
        self::$tags         = (array) ($payload->tags ?? []);
        self::$views        = (array) ($payload->views ?? []);
        self::$loaded       = [];
        self::$loading      = [];

        foreach (self::BOOTSTRAP_CLASSES as $class) {
            unset(self::$buffers[sha1($class)]);
        }
    }

    public static function load(string $class): bool
    {
        try {
            $class = ltrim($class, '\\');

            if (isset(self::$loaded[$class]) === true) {
                return true;
            }

            if (self::isDeclared($class) === true) {
                self::$loaded[$class] = true;

                return true;
            }

            if (isset(self::$loading[$class]) === true) {
                return true;
            }

            self::$loading[$class] = true;

            $dependencies = self::$dependencies[$class] ?? [];
            if ($dependencies !== []) {
                $dependencies = array_values(array_unique($dependencies));
            }

            foreach ($dependencies as $dependency) {
                if (self::load($dependency) === false) {
                    unset(self::$loading[$class]);

                    return false;
                }
            }

            if (self::isDeclared($class) === true) {
                self::$loaded[$class] = true;
                unset(self::$loading[$class]);

                return true;
            }

            $classHash = sha1($class);
            if (isset(self::$buffers[$classHash]) === false) {
                $mockHash = sha1(substr($class, 0, (int) strrpos($class, '\\')) ?: $class);
                if ($mockHash !== $classHash && isset(self::$buffers[$mockHash]) === true) {
                    $classHash = $mockHash;
                }
            }

            if (isset(self::$buffers[$classHash]) === true) {
                if (self::isDeclared($class) === true) {
                    unset(self::$buffers[$classHash]);
                    self::$loaded[$class] = true;
                    unset(self::$loading[$class]);

                    return true;
                }

                eval(self::$buffers[$classHash]);
                unset(self::$buffers[$classHash]);

                if (self::isDeclared($class) === false) {
                    unset(self::$loading[$class]);

                    return false;
                }

                self::$loaded[$class] = true;
                if (in_array($classHash, self::$constructors, true) === true) {
                    $class::__constructStatic();
                }

                unset(self::$loading[$class]);

                return true;
            }

            unset(self::$loading[$class]);
        } catch (\Exception|\Error $e) {
            unset(self::$loading[$class]);
            Error::handler($e);
        }

        return false;
    }

    private static function isDeclared(string $class): bool
    {
        return class_exists($class, false)
            || interface_exists($class, false)
            || trait_exists($class, false);
    }

    public static function getTagClass(string $tag): ?string
    {
        return self::$tags[$tag] ?? null;
    }

    /** @return array<string, string> */
    public static function getViews(): array
    {
        return self::$views;
    }
}
