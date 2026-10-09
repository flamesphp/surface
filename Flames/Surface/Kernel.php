<?php

declare(strict_types=1);

namespace Flames\Surface;

use Flames\Env\Env;
use Flames\Surface\Build\Assets\Automate;

/**
 * @internal
 */
class Kernel
{
    public static function inject(string $html): string
    {
        $html = self::ensureDoctype($html);
        $html = self::ensureMetaCharset($html);

        if (Env::get('SURFACE_ENABLED') !== true) {
            return $html;
        }

        return self::injectScript($html);
    }

    private static function ensureDoctype(string $html): string
    {
        if (preg_match('/^\s*<!DOCTYPE\s+html>/i', $html) === 1) {
            return preg_replace('/^\s*<!DOCTYPE\s+html>\s*/i', "<!DOCTYPE html>\n", $html, 1);
        }

        if (preg_match('/^\s*<!DOCTYPE[^>]*>/i', $html) === 1) {
            return preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', "<!DOCTYPE html>\n", $html, 1);
        }

        return "<!DOCTYPE html>\n" . ltrim($html);
    }

    private static function ensureMetaCharset(string $html): string
    {
        if (preg_match('/<meta\b[^>]*\bcharset\s*=/i', $html) === 1) {
            return $html;
        }

        $meta = '<meta charset="UTF-8">';

        if (preg_match('/<head\b[^>]*>/i', $html, $headMatch, PREG_OFFSET_CAPTURE) === 1) {
            $headOpenEnd = $headMatch[0][1] + strlen($headMatch[0][0]);

            return substr($html, 0, $headOpenEnd) . $meta . substr($html, $headOpenEnd);
        }

        if (preg_match('/<html\b[^>]*>/i', $html, $htmlMatch, PREG_OFFSET_CAPTURE) === 1) {
            $htmlOpenEnd = $htmlMatch[0][1] + strlen($htmlMatch[0][0]);

            return substr($html, 0, $htmlOpenEnd) . '<head>' . $meta . '</head>' . substr($html, $htmlOpenEnd);
        }

        return '<head>' . $meta . '</head>' . $html;
    }

    private static function injectScript(string $html): string
    {
        $script = self::buildScriptTag();

        if (preg_match('/<head\b[^>]*>/i', $html, $headMatch, PREG_OFFSET_CAPTURE) === 1) {
            $headOpenEnd = $headMatch[0][1] + strlen($headMatch[0][0]);

            if (preg_match('/<\/head>/i', $html, $headCloseMatch, PREG_OFFSET_CAPTURE, $headOpenEnd) === 1) {
                $headCloseStart = $headCloseMatch[0][1];
                $headContent = substr($html, $headOpenEnd, $headCloseStart - $headOpenEnd);

                if (preg_match('/<link\b[^>]*\brel\s*=\s*(["\'])stylesheet\1[^>]*>/i', $headContent, $cssMatch, PREG_OFFSET_CAPTURE) === 1) {
                    $insertPos = $headOpenEnd + $cssMatch[0][1];

                    return substr($html, 0, $insertPos) . $script . substr($html, $insertPos);
                }

                if (preg_match('/^\s*<meta\b[^>]*\bcharset\s*=[^>]*>/i', $headContent, $metaMatch, PREG_OFFSET_CAPTURE) === 1) {
                    $insertPos = $headOpenEnd + $metaMatch[0][1] + strlen($metaMatch[0][0]);

                    return substr($html, 0, $insertPos) . $script . substr($html, $insertPos);
                }

                return substr($html, 0, $headOpenEnd) . $script . substr($html, $headOpenEnd);
            }
        }

        if (preg_match('/<html\b[^>]*>/i', $html, $htmlMatch, PREG_OFFSET_CAPTURE) === 1) {
            $htmlOpenEnd = $htmlMatch[0][1] + strlen($htmlMatch[0][0]);
            $headBlock = '<head>' . $script . '</head>';

            return substr($html, 0, $htmlOpenEnd) . $headBlock . substr($html, $htmlOpenEnd);
        }

        return '<head>' . $script . '</head>' . $html;
    }

    private static function buildScriptTag(): string
    {
        $src = 'https://cdn.jsdelivr.net/gh/flamesphp/cdn@' . Surface::VERSION . '/flames.js';

        $tags = '<script async src="' . htmlspecialchars($src, ENT_QUOTES | ENT_COMPAT, 'UTF-8') . '"></script>';

        if (Env::get('AUTO_BUILD_CLIENT') === true) {
            $hash = new Automate()->getCurrentHash();
            $tags .= '<flames-autobuild style="display:none;">' . htmlspecialchars($hash, ENT_QUOTES | ENT_COMPAT, 'UTF-8') . '</flames-autobuild>';
        }

        return $tags;
    }
}
