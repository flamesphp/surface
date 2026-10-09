<?php

declare(strict_types=1);

namespace Flames\Surface\Build;

use Flames\Env\Parser;

/**
 * Collects .env entries marked with #[Public] for the client bundle.
 *
 * @internal
 */
final class PublicEnv
{
    /** @return array<string, mixed> */
    public static function collect(): array
    {
        $path = ROOT_PATH . '.env';
        if (!is_file($path)) {
            return [];
        }

        $parser = new Parser();
        if (!$parser->parseFile($path)) {
            return [];
        }

        $all    = $parser->get();
        $public = [];

        foreach ($parser->getPublicKeys() as $key) {
            if (array_key_exists($key, $all)) {
                $public[$key] = $all[$key];
            }
        }

        return $public;
    }
}
