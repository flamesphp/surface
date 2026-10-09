<?php

/*
 * Bundled by Surface after Virtual bootstrap in the same eval:
 * must use bracketed namespace syntax and no declare(strict_types).
 */
namespace Flames\Surface\Runtime
{
    /**
     * @internal
     */
    final class AutoLoad
    {
        public static function run(): void
        {
            \spl_autoload_register(function ($name) {
                \Flames\Surface\Runtime\Virtual::load(ltrim((string) $name, '\\'));
            });
        }
    }
}
