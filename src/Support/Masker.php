<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Support;

use Illuminate\Support\Facades\Config;

/**
 * Redacts what a provider has no business seeing.
 *
 * Applied at the Assistant boundary rather than as SDK prompt middleware.
 * Middleware rewrites the prompt on its way to the provider, but the store
 * persists the prompt the caller handed in, so the raw address would be
 * written to the database and replayed to the provider on the next turn as
 * history. Masking before the SDK sees the text closes both.
 */
class Masker
{
    public function mask(string $text): string
    {
        if (! Config::boolean('aria.masking.enabled', true)) {
            return $text;
        }

        $patterns = Config::array('aria.masking.patterns', []);

        foreach ($patterns as $label => $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            $replaced = preg_replace($pattern, '['.mb_strtoupper((string) $label).' REDACTED]', $text);

            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }
}
