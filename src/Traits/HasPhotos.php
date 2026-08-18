<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Traits;

use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Exceptions\InvalidPhotoException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\UnsupportedDiskOperation;
use Behzadsp\EloquentDynamicPhotos\PathGenerator;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\PhotoProcessor;
use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

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

    /**
     * Store a new photo for a field, replacing any existing one.
     *
     * Ordering matters and is deliberate: the new file is written, then the row
     * is saved, then declared conversions are (re)generated, and only then is
     * the old file removed. A crash midway leaves an orphaned file, which is
     * recoverable. v1 deleted first, which could leave the column pointing at
     * a file that no longer existed.
     *
     * Calls save(), so any other unsaved changes on the model are persisted
     * too.
     */
    public function updatePhoto(mixed $photo, string $field): static
    {
        $definition = $this->eloquentPhotoDefinition($field);
        $oldPath = $this->eloquentPhotoUsableRawPath($field);

        $newPath = $this->eloquentPhotoPathGenerator()->primaryPath($this, $definition);

        $this->eloquentPhotoProcessor()->storePrimary($photo, $definition->disk, $newPath, $definition);

        $this->setAttribute($field, $newPath);

        try {
            $this->save();
        } catch (Throwable $e) {
            // Restore the in-memory column first, so that always happens even
            // if the compensating delete below also fails.
            $this->setAttribute($field, $oldPath);

            try {
                // Best-effort: nothing durable happened, so this is tidying up
                // an orphan, not something the caller needs to hear about. If
                // it fails too, $e (why the save failed) is what matters.
                // Exception, not Throwable: a disk hiccup is swallowed, but an
                // Error (TypeError, a programming fault) is not this catch's
                // job to hide and must still crash.
                $this->eloquentPhotoDisk($field)->delete($newPath);
            } catch (Exception) {
                // Swallowed — see comment above.
            }

            throw $e;
        }

        // Not best-effort, unlike the old-file cleanup below: a caller who
        // declares a conversion expects it to exist. The primary is already
        // saved at this point, so a failure here doesn't undo that, but it
        // must still surface — swallowing it would leave photoPath()/photoUrl()
        // pointing at a conversion that silently doesn't exist, which is a
        // worse failure mode than a thrown exception the caller can see and
        // act on (including by calling regeneratePhotoConversions() again
        // later, since it's idempotent).
        $this->regeneratePhotoConversions($field);

        if ($oldPath !== null && $oldPath !== $newPath) {
            try {
                // Best-effort: the save already succeeded, so a failure here
                // must not turn a successful update into an error. It only
                // leaves an orphaned file behind. Exception, not Throwable —
                // see the comment in the catch block above.
                $this->eloquentPhotoDeleteFilesAt($field, $oldPath);
            } catch (Exception) {
                // Swallowed — see comment above.
            }
        }

        return $this;
    }

    /**
     * Build every declared conversion from the currently stored primary file.
     *
     * Idempotent — regenerating an existing conversion overwrites it — so this
     * doubles as the backfill for photos stored before a conversion was
     * declared.
     *
     * Uses eloquentPhotoRawPath(), not the ...UsableRawPath() variant: this
     * method is public and writes files at paths derived from the primary, so
     * a tampered column (a ".." segment or null byte) must fail loudly here
     * exactly as it does in photoPath(), rather than being silently treated
     * as "no photo". Silently no-op-ing would let a caller believe conversions
     * were (re)generated when a tampered row was actually never touched.
     */
    public function regeneratePhotoConversions(string $field): void
    {
        $definition = $this->eloquentPhotoDefinition($field);
        $primary = $this->eloquentPhotoRawPath($field);

        if ($primary === null || $definition->conversions === []) {
            return;
        }

        $generator = $this->eloquentPhotoPathGenerator();
        $processor = $this->eloquentPhotoProcessor();

        foreach ($definition->conversions as $name => $conversion) {
            $format = $this->eloquentConversionFormat($definition, $conversion);

            $processor->storeConversion(
                $definition->disk,
                $primary,
                $generator->conversionPath($primary, (string) $name, $format),
                $definition,
                $conversion,
            );
        }
    }

    /**
     * Delete a field's files and clear its column.
     *
     * A stored value that fails the tamper check in eloquentPhotoRawPath() is
     * still cleared: refusing to hand back an escaping path is right, but
     * refusing to let anyone clean up leaves the row bricked, recoverable
     * only via raw SQL. Nothing is deleted from disk in that case, hence
     * false, but the column is fixed.
     *
     * Ordering mirrors updatePhoto(): the column is cleared and saved first,
     * and only then are the files removed. Deleting first would leave the
     * column pointing at a file that no longer existed if the save then
     * failed — the same dead-pointer bug updatePhoto() exists to avoid.
     *
     * Calls save(), so any other unsaved changes on the model are persisted
     * too.
     */
    public function deletePhoto(string $field): bool
    {
        $rawValue = $this->attributes[$field] ?? null;

        if (! is_string($rawValue) || $rawValue === '') {
            return false;
        }

        $oldPath = $this->eloquentPhotoUsableRawPath($field);

        $this->setAttribute($field, null);
        $this->save();

        try {
            // Best-effort: the column is already cleared and saved, so a
            // failure here must not turn a successful delete into an error.
            // It only leaves an orphaned file behind. Exception, not
            // Throwable — see the comment in updatePhoto()'s catch block.
            return $this->eloquentPhotoDeleteFilesAt($field, $oldPath);
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Delete a field's files and leave its column as it is.
     *
     * v1 called this deletePhotoFile and returned true whenever the column was
     * non-empty, whether or not the delete actually happened.
     */
    public function deletePhotoFiles(string $field): bool
    {
        return $this->eloquentPhotoDeleteFilesAt($field, $this->eloquentPhotoUsableRawPath($field));
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

        $format = $this->eloquentConversionFormat($definition, $definition->conversion($conversion));

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

        $disk = $this->eloquentPhotoDisk($field);

        // Introspect the resolved adapter rather than a "driver" key read from
        // config: Storage::fake() swaps in a LocalFilesystemAdapter directly
        // (Storage::set()) without ever writing that key back into
        // filesystems.disks config, so a config-based check throws on a disk
        // that is genuinely local — inside exactly the faked-storage tests a
        // consuming app would write. This works for both a real local disk and
        // a faked one, and still refuses S3 (a different Flysystem adapter
        // class), without depending on method_exists($disk, 'path') — that is
        // declared on FilesystemAdapter and inherited by every driver
        // including S3, so it is true for every disk and proves nothing.
        if (! $disk instanceof FilesystemAdapter || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new UnsupportedDiskOperation(sprintf(
                'Disk [%s] is not a local filesystem, so it has no filesystem path. Use photoUrl() instead.',
                $this->eloquentPhotoDefinition($field)->disk
            ));
        }

        return $disk->path($path);
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
     *
     * PathGenerator never writes a ".." segment or a null byte into a stored
     * path — names are slugified and directories are validated at
     * declaration time — so a stored value containing either has been
     * tampered with from outside this package (most plausibly a
     * mass-assigned column, since $guarded = [] is a common setup on models
     * using this trait). path() on the local disk concatenates without
     * normalising and Flysystem's traversal guard only fires on real I/O, so
     * left unchecked this could resolve to a path outside the disk's root;
     * this fails loudly instead.
     */
    protected function eloquentPhotoRawPath(string $field): ?string
    {
        $value = $this->attributes[$field] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        // A null byte defeats the exact ".." segment comparison below
        // ("..\0" !== ".."), so it is rejected up front rather than folded
        // into the segment loop.
        if (str_contains($value, "\0")) {
            throw new InvalidPhotoException(sprintf(
                'The [%s] column on %s contains a null byte and cannot be used as a path.',
                $field,
                static::class
            ));
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '..') {
                throw new InvalidPhotoException(sprintf(
                    'The [%s] column on %s contains a ".." path segment: [%s].',
                    $field,
                    static::class,
                    $value
                ));
            }
        }

        return $value;
    }

    /**
     * Like eloquentPhotoRawPath(), but never throws: a tampered value is
     * treated the same as an empty column, both meaning "no path here that
     * can be safely acted on".
     *
     * eloquentPhotoRawPath() must keep throwing for the read paths above —
     * this package never hands back a path that could escape the disk root.
     * But the write and cleanup methods need the opposite failure mode: a
     * column corrupted by, e.g., $guarded = [] plus mass assignment should be
     * fixable by overwriting or clearing it, not permanently bricked because
     * every method that touches it throws first. Callers here never perform
     * filesystem I/O with a null result, only skip it.
     */
    protected function eloquentPhotoUsableRawPath(string $field): ?string
    {
        try {
            return $this->eloquentPhotoRawPath($field);
        } catch (InvalidPhotoException) {
            return null;
        }
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

    /**
     * A conversion inherits the field's format unless it declares its own.
     * Pulled out so that rule lives in exactly one place, used everywhere a
     * conversion's actual encoded format is needed.
     */
    private function eloquentConversionFormat(PhotoDefinition $definition, Conversion $conversion): string
    {
        return $conversion->format ?? $definition->format;
    }

    /**
     * Remove a primary file and every conversion derived from it.
     *
     * Deletes the primary's whole conversion directory rather than deriving
     * paths from what is currently declared: declared conversions are never
     * recorded anywhere, so a name-by-name delete would miss a conversion
     * that has since been removed, renamed, or reformatted, leaking its file
     * permanently. The directory is deleted whole instead, which covers all
     * of them regardless of what is currently declared, and is a no-op if it
     * never existed (e.g. a field with no declared conversions).
     */
    protected function eloquentPhotoDeleteFilesAt(string $field, ?string $primaryPath): bool
    {
        if ($primaryPath === null) {
            return false;
        }

        $disk = $this->eloquentPhotoDisk($field);

        // Report what the filesystem reports, for the primary file.
        $deleted = $disk->delete($primaryPath);

        $disk->deleteDirectory($this->eloquentPhotoPathGenerator()->conversionDirectory($primaryPath));

        return $deleted;
    }
}
