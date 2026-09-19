<?php

declare(strict_types=1);

namespace NoriaLabs\Aria\Middleware;

use Closure;

/**
 * Redacts what a provider has no business seeing before a prompt leaves.
 *
 * Patterns are config, not code: what counts as sensitive differs by product
 * and by country, and the list should be editable without a release.
 */
class MaskSensitiveData
{
    public function handle(mixed $prompt, Closure $next): mixed
    {
        $patterns = config('aria.masking.patterns', []);

        if (! is_array($patterns) || $patterns === []) {
            return $next($prompt);
        }

        if (is_object($prompt) && property_exists($prompt, 'text') && is_string($prompt->text)) {
            $prompt->text = self::mask($prompt->text, $patterns);
        }

        return $next($prompt);
    }

    /**
     * @param  array<string, string>  $patterns
     */
    public static function mask(string $text, array $patterns): string
    {
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
