<?php

declare(strict_types=1);

namespace Flames\Surface\Runtime\Dispatch;

use Flames\Js;
use Flames\Surface\Runtime\Virtual;

/**
 * @internal
 */
final class Tag
{
    /** @var array<string, array<int, object>> */
    protected static array $tags = [];

    public static function run(string $tagUid, int $shadowId): void
    {
        if (isset(self::$tags[$tagUid]) === false) {
            self::$tags[$tagUid] = [];
        }

        $tagClass = Virtual::getTagClass($tagUid);
        if ($tagClass === null) {
            return;
        }

        Virtual::load($tagClass);

        $window       = Js::getWindow();
        $shadowNative = ($window->Flames->Internal->tags->{$tagUid}->shadows->{$shadowId});
        self::$tags[$tagUid][$shadowId] = new $tagClass($shadowNative);
    }

    public static function render(string $tagUid, int $shadowId): void
    {
        if (isset(self::$tags[$tagUid]) === false) {
            return;
        }

        if (isset(self::$tags[$tagUid][$shadowId]) === false) {
            return;
        }

        self::$tags[$tagUid][$shadowId]->onRender();
    }
}
