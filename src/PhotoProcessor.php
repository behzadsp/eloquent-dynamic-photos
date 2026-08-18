<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos;

use Behzadsp\EloquentDynamicPhotos\Exceptions\InvalidPhotoException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Intervention\Image\Exceptions\ArgumentException;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\Exceptions\FilesystemException;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use SplFileInfo;
use Throwable;

/**
 * Decodes, transforms and encodes image data. Knows nothing about models or the
 * database — it takes a source and a disk-relative path and writes bytes.
 */
final class PhotoProcessor
{
    public function __construct(
        private readonly ImageManager $images,
        private readonly FilesystemFactory $filesystem,
    ) {}

    public function storePrimary(mixed $source, string $disk, string $path, PhotoDefinition $definition): void
    {
        $image = $this->decode($source, $definition);

        if ($definition->autoOrient) {
            // Needs ext-exif; without it this is a no-op.
            $image->orient();
        }

        if ($definition->maxWidth !== null || $definition->maxHeight !== null) {
            $image->scaleDown(width: $definition->maxWidth, height: $definition->maxHeight);
        }

        $this->put($disk, $path, $image, $definition->format, $definition->quality);
    }

    public function storeConversion(
        string $disk,
        string $sourcePath,
        string $targetPath,
        PhotoDefinition $definition,
        Conversion $conversion,
    ): void {
        $binary = $this->filesystem->disk($disk)->get($sourcePath);

        if ($binary === null) {
            throw new InvalidPhotoException(sprintf(
                'Cannot build a conversion: [%s] is missing from disk [%s].',
                $sourcePath,
                $disk
            ));
        }

        $image = $this->images->decodeBinary($binary);

        $image = match ($conversion->fit) {
            Fit::Contain => $image->scaleDown(width: $conversion->width, height: $conversion->height),
            // Conversion's constructor guarantees both axes for these two fits.
            Fit::Cover => $image->cover($this->axis($conversion->width), $this->axis($conversion->height)),
            Fit::Stretch => $image->resize($this->axis($conversion->width), $this->axis($conversion->height)),
        };

        $this->put(
            $disk,
            $targetPath,
            $image,
            $conversion->format ?? $definition->format,
            $conversion->quality ?? $definition->quality,
        );
    }

    private function decode(mixed $source, PhotoDefinition $definition): ImageInterface
    {
        $this->assertValidSourceType($source);
        $this->assertWithinSizeLimit($source, $definition);
        $this->assertWithinPixelLimit($source, $definition);

        try {
            // decode() dispatches on the source type for everything except a
            // stream resource, which needs the explicit entry point.
            $image = is_resource($source)
                ? $this->images->decodeStream($source)
                : $this->images->decode($source);
        } catch (DecoderException|FilesystemException|ArgumentException $e) {
            // decode() picks a decoder by guessing the input's shape: an
            // all-printable-ASCII string that is not valid image data still
            // "looks like" a file path to that guess, so a garbage string
            // reaches FilePathImageDecoder and fails with FileNotFoundException
            // (a FilesystemException) rather than a DecoderException. ArgumentException
            // is the third family, for shapes assertValidSourceType() lets through
            // but decode() still can't place (belt-and-braces; it should be
            // unreachable given the guard above). All three mean the same thing
            // here: this source could not become an image.
            throw new InvalidPhotoException(
                'The given source could not be decoded as an image.',
                previous: $e
            );
        }

        // The media type comes from the decoded bytes, not from a filename or a
        // client-supplied Content-Type, so a JPEG named .png cannot slip through.
        $mediaType = $image->origin()->mediaType();

        if (! in_array($mediaType, $definition->allowedMimeTypes, true)) {
            throw new InvalidPhotoException(sprintf(
                'Images of type [%s] are not allowed. Allowed: %s.',
                $mediaType,
                implode(', ', $definition->allowedMimeTypes)
            ));
        }

        return $image;
    }

    /**
     * decode() otherwise dispatches by guessing at the input's shape, so a
     * caller mistake (e.g. $request->file('photo') being null because nothing
     * was uploaded) reaches Intervention and leaks one of its own exceptions
     * through this package's boundary instead of an InvalidPhotoException.
     */
    private function assertValidSourceType(mixed $source): void
    {
        $isAcceptable = $source instanceof SplFileInfo
            || is_resource($source)
            || (is_string($source) && $source !== '');

        if (! $isAcceptable) {
            throw new InvalidPhotoException(sprintf(
                'A photo source must be an uploaded file, a path, binary image data or a stream, %s given.',
                $source === '' ? 'an empty string' : get_debug_type($source)
            ));
        }
    }

    private function assertWithinSizeLimit(mixed $source, PhotoDefinition $definition): void
    {
        $bytes = match (true) {
            $source instanceof SplFileInfo => $source->isFile() ? $source->getSize() : null,
            is_string($source) => $this->looksLikeAPath($source) && is_file($source)
                ? filesize($source)
                : strlen($source),
            is_resource($source) => $this->resourceSize($source),
            default => null,
        };

        if ($bytes === false || $bytes === null) {
            return;
        }

        $limit = $definition->maxFileSize * 1024;

        if ($bytes > $limit) {
            throw new InvalidPhotoException(sprintf(
                'The image is %d KB, which exceeds the %d KB limit.',
                (int) ceil($bytes / 1024),
                $definition->maxFileSize
            ));
        }
    }

    /**
     * Compressed byte size is not a proxy for decode memory: a highly
     * compressible image can be a small file and an enormous bitmap, and GD
     * must materialise the full bitmap before scaleDown() can shrink it. This
     * reads dimensions from the header only, before decode() would otherwise
     * build that bitmap.
     */
    private function assertWithinPixelLimit(mixed $source, PhotoDefinition $definition): void
    {
        if ($definition->maxPixels === null) {
            return;
        }

        $dimensions = $this->probeDimensions($source);

        if ($dimensions === null) {
            // Could not determine dimensions cheaply (e.g. a non-seekable
            // stream). Do not fail closed: fall through to decode(), where the
            // byte-size guard above and PHP's memory_limit still apply.
            return;
        }

        [$width, $height] = $dimensions;
        $pixels = $width * $height;

        if ($pixels > $definition->maxPixels) {
            throw new InvalidPhotoException(sprintf(
                'The image is %dx%d (%d pixels), which exceeds the %d pixel limit.',
                $width,
                $height,
                $pixels,
                $definition->maxPixels
            ));
        }
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function probeDimensions(mixed $source): ?array
    {
        $info = match (true) {
            $source instanceof SplFileInfo => $source->isFile() ? getimagesize($source->getPathname()) : false,
            is_resource($source) => $this->probeStreamDimensions($source),
            is_string($source) => $this->looksLikeAPath($source) && is_file($source)
                ? getimagesize($source)
                : getimagesizefromstring($source),
            default => false,
        };

        return is_array($info) ? [$info[0], $info[1]] : null;
    }

    /**
     * @param  resource  $stream
     * @return array{0: int, 1: int}|false
     */
    private function probeStreamDimensions(mixed $stream): array|false
    {
        $meta = stream_get_meta_data($stream);

        if (! $meta['seekable']) {
            // Cannot read a header chunk without consuming bytes decodeStream()
            // still needs, and this stream cannot be rewound afterwards.
            return false;
        }

        rewind($stream);
        $header = stream_get_contents($stream, 65536);
        rewind($stream);

        if ($header === false) {
            return false;
        }

        $info = getimagesizefromstring($header);

        return is_array($info) ? [$info[0], $info[1]] : false;
    }

    /**
     * Cheap guard before handing a string to is_file(). Raw image data is long
     * and contains null bytes; a filesystem path is neither.
     */
    private function looksLikeAPath(string $value): bool
    {
        return strlen($value) < 4096 && ! str_contains($value, "\0");
    }

    /**
     * fstat() is cheap and does not consume the stream, unlike reading it to
     * measure. A non-seekable stream (a pipe, a socket) reports a 0 size here
     * rather than throwing, which would otherwise silently defeat this guard
     * entirely — treated the same as "unknown" (null), since it genuinely
     * cannot be measured this way. The byte guard simply does not apply to it;
     * the pixel-dimension guard and PHP's memory_limit still do.
     *
     * @param  resource  $resource
     */
    private function resourceSize(mixed $resource): ?int
    {
        $stat = fstat($resource);
        $size = $stat !== false ? $stat['size'] ?? 0 : 0;

        return $size > 0 ? $size : null;
    }

    private function put(string $disk, string $path, ImageInterface $image, string $format, int $quality): void
    {
        try {
            // Format::create() takes the format string straight from config, so
            // there is no per-format branching anywhere in this package. Every
            // encoder accepts a quality option, lossless ones included.
            $encoder = Format::create($format)->encoder(quality: $quality);
        } catch (Throwable $e) {
            throw new PhotoDefinitionException(
                sprintf('[%s] is not a format this package can encode.', $format),
                previous: $e
            );
        }

        // One encode. v1 encoded twice by discarding the result of the first.
        $this->filesystem->disk($disk)->put($path, $image->encode($encoder)->toString());
    }

    private function axis(?int $value): int
    {
        // Unreachable in practice: Conversion rejects a Cover or Stretch fit that
        // is missing an axis. Not an assert() — those compile out under
        // zend.assertions=-1, which would return null from an int method.
        return $value ?? throw new PhotoDefinitionException(
            'This fit needs both a width and a height.'
        );
    }
}
