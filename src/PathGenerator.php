<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Builds every storage path this package writes to. All filename construction
 * routes through sanitize(), which is the only reason a user-controlled name
 * attribute cannot escape the intended directory.
 */
final class PathGenerator
{
    public function __construct(private readonly string $root) {}

    /**
     * Reduce an arbitrary attribute value to a safe filename fragment.
     *
     * Str::slug() drops path separators, whitespace and non-ASCII, which is what
     * makes "../../etc/passwd" harmless. It can also reduce a string to nothing
     * at all — Str::slug('日本語') is '' — hence the fallback chain.
     */
    public function sanitize(string $raw, int $limit, string $fallback = ''): string
    {
        $slug = $this->truncate(Str::slug($raw), $limit);

        if ($slug === '') {
            $slug = $this->truncate(Str::slug($fallback), $limit);
        }

        return $slug === '' ? Str::random(8) : $slug;
    }

    public function prefix(Model $model, PhotoDefinition $definition): string
    {
        if ($definition->directory !== null) {
            return trim($definition->directory, '/');
        }

        $modelDirectory = Str::of(class_basename($model))->plural()->lower()->toString();
        $root = trim($this->root, '/');

        return $root === '' ? $modelDirectory : $root.'/'.$modelDirectory;
    }

    public function filename(Model $model, PhotoDefinition $definition): string
    {
        $raw = $model->getAttribute($definition->nameAttribute);
        $key = $model->getKey();

        $name = $this->sanitize(
            is_scalar($raw) ? (string) $raw : '',
            $definition->nameLimit,
            is_scalar($key) ? (string) $key : '',
        );

        return $name
            .'_'.Str::random(5)
            .'_'.Carbon::now()->format($definition->timestampFormat);
    }

    public function primaryPath(Model $model, PhotoDefinition $definition): string
    {
        return $this->prefix($model, $definition)
            .'/'.$this->filename($model, $definition)
            .'.'.$definition->format;
    }

    /**
     * Conversion paths are derived from the stored primary path rather than
     * recorded, which is why v2 needs no schema change.
     */
    public function conversionPath(string $primaryPath, string $conversionName, string $format): string
    {
        $directory = dirname($primaryPath);
        $basename = pathinfo($primaryPath, PATHINFO_FILENAME);

        // dirname() returns "." for a path with no directory component.
        $prefix = ($directory === '.' || $directory === '') ? '' : $directory.'/';

        return $prefix.'conversions/'.$basename.'-'.$conversionName.'.'.$format;
    }

    private function truncate(string $value, int $limit): string
    {
        // The empty terminator is the point: Str::limit() otherwise appends "...".
        return trim(Str::limit($value, $limit, ''), '-');
    }
}
