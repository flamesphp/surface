<?php

declare(strict_types=1);

namespace Flames\Surface\Build\Assets;

use Flames\Collection\Arr;
use Flames\Client\Event;

/**
 * Mounts and caches reflection data for a given client-side class.
 */
class Data
{
    private const int VERSION  = 5;

    public static function mountData(string $class): Arr
    {
        $path        = ROOT_PATH . str_replace('\\', '/', $class) . '.php';
        $cachePath   = self::cacheDir() . sha1($class);
        $currentTime = filemtime($path);

        if (file_exists($cachePath) && filemtime($cachePath) === $currentTime) {
            $data = unserialize((string)file_get_contents($cachePath));
            if (isset($data->version) && $data->version === self::VERSION) {
                return $data;
            }
        }

        $data    = self::buildReflection($class);
        $written = @file_put_contents($cachePath, serialize($data));

        if ($written === false) {
            if (!is_dir(self::cacheDir())) {
                $mask = umask(0);
                mkdir(self::cacheDir(), 0777, true);
                umask($mask);
            }
            @file_put_contents($cachePath, serialize($data));
        }

        @touch($cachePath, $currentTime);

        return $data;
    }

    private static function buildReflection(string $class): Arr
    {
        $data = new Arr([
            'version'         => self::VERSION,
            'class'           => $class,
            'methods'         => new Arr(),
            'staticConstruct' => method_exists($class, '__constructStatic'),
        ]);

        $reflection = new \ReflectionClass($class);

        foreach ($reflection->getMethods() as $method) {
            if ($method->name === 'success' || $method->name === 'error' || $method->name === '__constructStatic') {
                continue;
            }

            foreach ($method->getAttributes() as $attribute) {
                $name = $attribute->getName();

                $type = match ($name) {
                    Event\Click::class  => 'click',
                    Event\Change::class => 'change',
                    Event\Input::class  => 'input',
                    default             => null,
                };

                if ($type === null) {
                    continue;
                }

                $arguments = $attribute->getArguments();
                if (!isset($arguments['uid'])) {
                    continue;
                }

                $data->methods[$method->name] = new Arr([
                    'name' => $method->name,
                    'uid'  => $arguments['uid'],
                    'type' => $type,
                ]);
            }
        }

        return $data;
    }

    private static function cacheDir(): string
    {
        return rtrim(\Flames\Framework\Cache::getPath(), '/') . '/.flames/client-controller/';
    }
}
