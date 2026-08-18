<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Traits;

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;

/**
 * Shared guard for the short, single-segment strings that reach a stored path
 * without going through PathGenerator::sanitize() — conversion names and
 * format strings. These are developer-chosen labels, not user data, so a
 * strict allowlist is the right shape: refuse anything outside it rather
 * than rewrite it.
 */
trait ValidatesPathTokens
{
    private static function assertPathToken(string $value, string $label, string $pattern): void
    {
        if (preg_match($pattern, $value) !== 1) {
            throw new PhotoDefinitionException(sprintf(
                '%s [%s] must match the pattern %s.',
                $label,
                $value,
                $pattern
            ));
        }
    }
}
