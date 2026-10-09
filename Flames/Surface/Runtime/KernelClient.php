<?php

declare(strict_types=1);

namespace Flames\Surface\Runtime;

use Flames\Js;

/**
 * @internal
 */
final class KernelClient
{
    public const string VERSION = '2.0.0';
    public const string MODULE  = 'CLIENT';

    private static bool $isNativeBuild = false;

    private static mixed $data = null;

    private static bool $getData = false;

    public static function __getData(): object
    {
        if (self::$getData === false) {
            $flamesElement = Js::getWindow()->document->querySelector('flames');
            if ($flamesElement === null) {
                self::$data    = (object) [];
                self::$getData = true;

                return self::$data;
            }
            $data = base64_decode($flamesElement->innerHTML);
            try {
                $data = substr($data, strpos($data, '|') + 1);
                $data = substr($data, strpos($data, '|') + 1);
                $data = substr($data, strpos($data, '|') + 1);
                $data = unserialize($data);
            } catch (\Exception|\Error) {
            }
            if ($data === false) {
                $data = (object) [];
            }
            self::$data    = $data;
            self::$getData = true;
            $flamesElement->remove();
        }

        return self::$data;
    }

    public static function __injectData(mixed $data): void
    {
        $data = base64_decode($data);
        try {
            $data = substr($data, strpos($data, '|') + 1);
            $data = substr($data, strpos($data, '|') + 1);
            $data = substr($data, strpos($data, '|') + 1);
            $data = unserialize($data);
        } catch (\Exception|\Error) {
        }
        if ($data === false) {
            $data = (object) [];
        }
        self::$data    = $data;
        self::$getData = true;
    }

    public static function __injector(): void
    {
        self::__getData();
    }

    public static function isNativeBuild(): bool
    {
        return self::$isNativeBuild;
    }

    public static function __setNativeBuild(bool $nativeBuild): bool
    {
        return self::$isNativeBuild = $nativeBuild;
    }
}
