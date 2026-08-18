<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Traits;

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\UnsupportedDiskOperation;
use Behzadsp\EloquentDynamicPhotos\PathGenerator;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\PhotoProcessor;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin Model
 */
trait HasPhotos
{
    /** @var array<string, PhotoDefinition>|null */
    private ?array $eloquentPhotoDefinitionsCache = null;

    /**
     * Declare which columns hold photos, and how each one is handled.
     *
     * Override this in the model. Keys are column names.
     *
     * @return array<string, PhotoDefinition>
     */
    protected function photoDefinitions(): array
    {
        return [];
    }

    public function photoPath(string $field, ?string $conversion = null): ?string
    {
        $definition = $this->eloquentPhotoDefinition($field);
        $primary = $this->eloquentPhotoRawPath($field);

        if ($primary === null) {
            return null;
        }

        if ($conversion === null) {
            return $primary;
        }

        $format = $definition->conversion($conversion)->format ?? $definition->format;

        return $this->eloquentPhotoPathGenerator()->conversionPath($primary, $conversion, $format);
    }

    public function photoUrl(string $field, ?string $conversion = null): ?string
    {
        $path = $this->photoPath($field, $conversion);

        if ($path === null) {
            return null;
        }

        return $this->eloquentPhotoDisk($field)->url($path);
    }

    public function photoFullPath(string $field, ?string $conversion = null): ?string
    {
        $path = $this->photoPath($field, $conversion);

        if ($path === null) {
            return null;
        }

        $disk = $this->eloquentPhotoDefinition($field)->disk;
        $driver = config('filesystems.disks.'.$disk.'.driver');

        // Checked against the configured driver, not method_exists($disk, 'path'):
        // path() is declared on FilesystemAdapter and inherited by every driver
        // including S3, so the method always exists. On a remote disk it returns a
        // prefixed object key rather than a filesystem path, which is what v1
        // handed back to callers as though it were one.
        if ($driver !== 'local') {
            throw new UnsupportedDiskOperation(sprintf(
                'Disk [%s] uses the [%s] driver, so it has no local filesystem path. Use photoUrl() instead.',
                $disk,
                is_string($driver) ? $driver : get_debug_type($driver)
            ));
        }

        return $this->eloquentPhotoDisk($field)->path($path);
    }

    /**
     * @param  string  $key
     */
    public function getAttribute($key): mixed
    {
        // Gated on the declared fields, so a model with real website and
        // website_url columns is left alone.
        if (is_string($key) && str_ends_with($key, '_url')) {
            // substr, not str_replace: str_replace('_url', '') turns
            // "url_photo_url" into "_photo".
            $field = substr($key, 0, -4);

            if ($field !== '' && array_key_exists($field, $this->eloquentPhotoDefinitions())) {
                return $this->photoUrl($field);
            }
        }

        return parent::getAttribute($key);
    }

    /**
     * @return array<string, PhotoDefinition>
     */
    protected function eloquentPhotoDefinitions(): array
    {
        return $this->eloquentPhotoDefinitionsCache ??= $this->photoDefinitions();
    }

    protected function eloquentPhotoDefinition(string $field): PhotoDefinition
    {
        $definitions = $this->eloquentPhotoDefinitions();

        return $definitions[$field]
            ?? throw new PhotoDefinitionException(sprintf(
                'Field [%s] is not declared as a photo on %s. Declared: %s.',
                $field,
                static::class,
                $definitions === [] ? 'none' : implode(', ', array_keys($definitions))
            ));
    }

    /**
     * The raw column value, read straight off the attribute array so the _url
     * accessor above cannot recurse into it.
     */
    protected function eloquentPhotoRawPath(string $field): ?string
    {
        $value = $this->attributes[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function eloquentPhotoDisk(string $field): Filesystem
    {
        return Storage::disk($this->eloquentPhotoDefinition($field)->disk);
    }

    protected function eloquentPhotoPathGenerator(): PathGenerator
    {
        return app(PathGenerator::class);
    }

    protected function eloquentPhotoProcessor(): PhotoProcessor
    {
        return app(PhotoProcessor::class);
    }
}
