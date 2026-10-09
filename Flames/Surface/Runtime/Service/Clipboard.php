<?php

declare(strict_types=1);

namespace Flames\Surface\Runtime\Service;

use Flames\Element;
use Flames\Event\Clipboard\Paste;
use Flames\Surface\Runtime\Error;

/**
 * @internal
 */
class Clipboard
{
    protected static bool $isRegistered = false;

    /** @var list<\Closure> */
    protected static array $pasteDelegates = [];

    public static function registerPaste(\Closure $delegate): void
    {
        self::$pasteDelegates[] = $delegate;

        if (self::$isRegistered === false) {
            self::register();
        }
    }

    protected static function register(): void
    {
        self::$isRegistered = true;

        $element = Element::getBody();
        if ($element === null) {
            return;
        }

        $element->element->addEventListener('paste', function ($event) use ($element) {
            $pasteEvent = new Paste($event);

            $preventDefault = false;
            foreach (self::$pasteDelegates as $delegate) {
                try {
                    $return = $delegate($pasteEvent);
                    if ($return === false) {
                        $preventDefault = true;
                    }
                } catch (\Exception|\Error $e) {
                    Error::handler($e);

                    return null;
                }
            }

            if ($preventDefault === true) {
                $event->preventDefault();

                return false;
            }

            return null;
        });
    }
}
