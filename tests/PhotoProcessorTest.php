<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Exceptions\InvalidPhotoException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\StorageFailedException;
use Behzadsp\EloquentDynamicPhotos\Fit;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\PhotoProcessor;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\AnimationFactoryInterface;
use Intervention\Image\Interfaces\ImageInterface;

beforeEach(function (): void {
    Storage::fake('photos');
});

function processor(): PhotoProcessor
{
    return app(PhotoProcessor::class);
}

/**
 * An 8000x8000 solid-colour PNG: compresses to ~200 KB on disk but costs
 * hundreds of MB to decode, since GD must materialise the full bitmap before
 * it can be scaled down. Built once and cached — two tests need the same
 * bytes, and building it costs real memory.
 */
function hugeSolidPng(): string
{
    /** @var string|null $bytes */
    static $bytes = null;

    return $bytes ??= Fixtures::png(8000, 8000);
}

/**
 * An N-frame solid-colour GIF. Real animation-frame bytes are needed, not N
 * copies of a still: the guard under test counts Graphic Control Extension
 * markers in the encoded bytes, which only real frames produce.
 */
function animatedGif(int $frames, int $width, int $height): string
{
    return app(ImageManager::class)
        ->createImage($width, $height, function (AnimationFactoryInterface $animation) use ($frames): void {
            for ($i = 0; $i < $frames; $i++) {
                $animation->add($i % 2 === 0 ? '#ff0000' : '#00ff00', 0.04);
            }
        })
        ->encode(Format::create('gif')->encoder(quality: 90))
        ->toString();
}

/**
 * 100 frames at 800x800: 640,000 pixels per frame (well under the default
 * 40M ceiling) but 64,000,000 once every frame is counted — the evasion the
 * per-frame-only guard missed. Built once and cached, like hugeSolidPng().
 */
function hugeAnimatedGif(): string
{
    /** @var string|null $bytes */
    static $bytes = null;

    return $bytes ??= animatedGif(100, 800, 800);
}

/**
 * An N-frame solid-colour animated WebP, built directly with the imagick
 * driver rather than through the app's configured one: GD's imagewebp()
 * cannot write more than one frame regardless of config, so this fixture
 * cannot depend on whatever driver a given test has bound.
 */
function animatedWebp(int $frames, int $width, int $height): string
{
    return ImageManager::usingDriver(ImagickDriver::class)
        ->createImage($width, $height, function (AnimationFactoryInterface $animation) use ($frames): void {
            for ($i = 0; $i < $frames; $i++) {
                $animation->add($i % 2 === 0 ? '#ff0000' : '#00ff00', 0.04);
            }
        })
        ->encode(Format::create('webp')->encoder(quality: 90))
        ->toString();
}

/**
 * 100 frames at 800x800, same shape as hugeAnimatedGif(): 640,000 pixels per
 * frame but 64,000,000 once every frame counts. Built once and cached.
 */
function hugeAnimatedWebp(): string
{
    /** @var string|null $bytes */
    static $bytes = null;

    return $bytes ??= animatedWebp(100, 800, 800);
}

function storedImage(string $path): ImageInterface
{
    $binary = Storage::disk('photos')->get($path);

    if ($binary === null) {
        throw new RuntimeException("Fixture [{$path}] does not exist on the fake disk.");
    }

    return app(ImageManager::class)->decodeBinary($binary);
}

it('encodes to the configured format and stores it', function (): void {
    processor()->storePrimary(Fixtures::png(), 'photos', 'p/a.webp', new PhotoDefinition);

    Storage::disk('photos')->assertExists('p/a.webp');
    expect(storedImage('p/a.webp')->origin()->mediaType())->toBe('image/webp');
});

it('accepts a binary string, a path, an UploadedFile and a stream', function (): void {
    $definition = new PhotoDefinition;
    $binary = Fixtures::png();
    $tmp = tempnam(sys_get_temp_dir(), 'edp').'.png';
    file_put_contents($tmp, $binary);

    $sources = [
        'binary' => $binary,
        'path' => $tmp,
        'upload' => new UploadedFile($tmp, 'a.png', 'image/png', null, true),
        'stream' => fopen($tmp, 'rb'),
    ];

    foreach ($sources as $label => $source) {
        processor()->storePrimary($source, 'photos', "p/{$label}.webp", $definition);
        Storage::disk('photos')->assertExists("p/{$label}.webp");
    }
});

it('scales down to the configured bounding box without upscaling', function (): void {
    $definition = new PhotoDefinition(maxWidth: 20, maxHeight: 20);

    processor()->storePrimary(Fixtures::png(100, 50), 'photos', 'p/big.webp', $definition);
    processor()->storePrimary(Fixtures::png(10, 5), 'photos', 'p/small.webp', $definition);

    expect(storedImage('p/big.webp')->width())->toBe(20);
    expect(storedImage('p/big.webp')->height())->toBe(10);
    // Never upscaled.
    expect(storedImage('p/small.webp')->width())->toBe(10);
});

it('rejects a source that is not a decodable image', function (): void {
    processor()->storePrimary('this is not an image', 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(InvalidPhotoException::class, 'could not be decoded');

it('rejects a null source, naming what was given', function (): void {
    // The realistic trigger: $request->file('photo') is null when no file was uploaded.
    processor()->storePrimary(null, 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(InvalidPhotoException::class, 'null');

it('rejects an array source, naming what was given', function (): void {
    processor()->storePrimary([], 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(InvalidPhotoException::class, 'array');

it('rejects an integer source, naming what was given', function (): void {
    processor()->storePrimary(123, 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(InvalidPhotoException::class, 'int');

it('rejects an empty string source', function (): void {
    processor()->storePrimary('', 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(InvalidPhotoException::class);

it('rejects a stream over the size limit before trying to decode it', function (): void {
    // Streams used to skip the byte-size guard entirely: assertWithinSizeLimit's
    // match had no is_resource arm, so every stream fell through to `default
    // => null` (unknown, skip). This pins the fstat-based fix.
    $definition = new PhotoDefinition(maxFileSize: 1); // 1 KB
    $stream = fopen('php://temp', 'r+') ?: throw new RuntimeException('Could not open a temp stream.');
    fwrite($stream, str_repeat('x', 2048));
    rewind($stream);

    processor()->storePrimary($stream, 'photos', 'p/a.webp', $definition);
})->throws(InvalidPhotoException::class, 'exceeds');

it('stores a stream source within the size limit', function (): void {
    // No over-rejection: fstat must not punish a stream that is genuinely small.
    $definition = new PhotoDefinition(maxFileSize: 1); // 1 KB
    $stream = fopen('php://temp', 'r+') ?: throw new RuntimeException('Could not open a temp stream.');
    fwrite($stream, Fixtures::png());
    rewind($stream);

    processor()->storePrimary($stream, 'photos', 'p/a.webp', $definition);

    Storage::disk('photos')->assertExists('p/a.webp');
});

it('rejects an image whose pixel count exceeds the configured ceiling', function (): void {
    // Compressed byte size is not a proxy for decode memory: this is ~200 KB
    // on disk but a 64-megapixel bitmap once decoded.
    $definition = new PhotoDefinition(maxPixels: 1_000_000);

    processor()->storePrimary(hugeSolidPng(), 'photos', 'p/a.webp', $definition);
})->throws(InvalidPhotoException::class, '8000x8000');

it('allows an oversized image through when the pixel guard is disabled', function (): void {
    // Same nullable-config pattern as maxWidth/maxHeight: a constructor null
    // is indistinguishable from "omitted" and falls back to config, so
    // disabling requires the config value itself to be null.
    config()->set('eloquent_photo.max_pixels', null);

    processor()->storePrimary(hugeSolidPng(), 'photos', 'p/a.webp', new PhotoDefinition);

    Storage::disk('photos')->assertExists('p/a.webp');
});

it('leaves a normal image unaffected by the default pixel ceiling', function (): void {
    processor()->storePrimary(Fixtures::png(), 'photos', 'p/a.webp', new PhotoDefinition);

    Storage::disk('photos')->assertExists('p/a.webp');
});

it('rejects an animated image whose total pixel budget exceeds the ceiling', function (): void {
    // 100 frames at 800x800 is 640,000 pixels per frame -- comfortably under
    // the default 40M ceiling -- but 64,000,000 once every frame is
    // decoded. getimagesize() only ever reports frame one, so the guard must
    // fold frame count into the budget, not just width x height.
    processor()->storePrimary(hugeAnimatedGif(), 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(InvalidPhotoException::class, '64000000');

it('allows an animated image through when the pixel ceiling is high enough', function (): void {
    $definition = new PhotoDefinition(maxPixels: 100_000_000);

    processor()->storePrimary(hugeAnimatedGif(), 'photos', 'p/a.webp', $definition);

    Storage::disk('photos')->assertExists('p/a.webp');
});

it('does not treat a handful of animation frames as an attack', function (): void {
    processor()->storePrimary(animatedGif(3, 100, 100), 'photos', 'p/a.webp', new PhotoDefinition);

    Storage::disk('photos')->assertExists('p/a.webp');
});

it('leaves a static GIF unaffected by the pixel ceiling', function (): void {
    processor()->storePrimary(Fixtures::gif(), 'photos', 'p/a.webp', new PhotoDefinition);

    Storage::disk('photos')->assertExists('p/a.webp');
});

it('rejects an animated WebP whose total pixel budget exceeds the ceiling under the imagick driver', function (): void {
    // GD flattens animation to one frame on encode (see the earlier GD-driver
    // report), but Imagick's WebP encoder preserves it -- and both drivers
    // must decode every frame to get there. getimagesize() still only reports
    // frame one, so the same evasion the GIF fix closed exists for WebP too,
    // gated on this config, which is supported and installed here.
    config()->set('eloquent_photo.driver', 'imagick');
    app()->forgetInstance(ImageManager::class);

    processor()->storePrimary(hugeAnimatedWebp(), 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(InvalidPhotoException::class, '64000000')
    ->skip(! extension_loaded('imagick'), 'ext-imagick is not installed');

it('allows an animated WebP through when the pixel ceiling is high enough', function (): void {
    // The short (3-frame) fixture is enough to prove the ceiling is a tunable
    // rather than a hard block: 3 * 100 * 100 = 30,000 total pixels, just
    // under a 30,001 ceiling. Re-encoding the 100-frame fixture here would
    // only re-prove what "does not treat a handful of frames as an attack"
    // already covers, at real decode+encode cost.
    config()->set('eloquent_photo.driver', 'imagick');
    app()->forgetInstance(ImageManager::class);

    $definition = new PhotoDefinition(maxPixels: 30_001);

    processor()->storePrimary(animatedWebp(3, 100, 100), 'photos', 'p/a.webp', $definition);

    Storage::disk('photos')->assertExists('p/a.webp');
})->skip(! extension_loaded('imagick'), 'ext-imagick is not installed');

it('does not treat a handful of animated WebP frames as an attack', function (): void {
    config()->set('eloquent_photo.driver', 'imagick');
    app()->forgetInstance(ImageManager::class);

    processor()->storePrimary(animatedWebp(3, 100, 100), 'photos', 'p/a.webp', new PhotoDefinition);

    Storage::disk('photos')->assertExists('p/a.webp');
})->skip(! extension_loaded('imagick'), 'ext-imagick is not installed');

it('leaves a static WebP unaffected by the pixel ceiling', function (): void {
    processor()->storePrimary(Fixtures::webp(), 'photos', 'p/a.webp', new PhotoDefinition);

    Storage::disk('photos')->assertExists('p/a.webp');
});

it('does not multiply a static WebP by a zero frame count', function (): void {
    // A static WebP has zero ANMF chunks. If frameCount() returned that raw
    // count instead of clamping to at least 1, the pixel budget would be
    // width*height*0 = 0 and this would store instead of throwing.
    $definition = new PhotoDefinition(maxPixels: 100 * 100 - 1);

    processor()->storePrimary(Fixtures::webp(100, 100), 'photos', 'p/a.webp', $definition);
})->throws(InvalidPhotoException::class, '10000 total pixels');

it('rejects a path that does not exist', function (): void {
    // Pins existing behaviour: this already worked via the FilesystemException
    // branch, since a leading "/" makes Intervention's own heuristic guess it
    // could be a file path.
    processor()->storePrimary('/nonexistent/x.png', 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(InvalidPhotoException::class, 'could not be decoded');

it('rejects a media type outside the allowlist', function (): void {
    $definition = new PhotoDefinition(allowedMimeTypes: ['image/png']);

    processor()->storePrimary(Fixtures::jpeg(), 'photos', 'p/a.webp', $definition);
})->throws(InvalidPhotoException::class, 'image/jpeg');

it('checks the decoded media type rather than a claimed one', function (): void {
    // A JPEG named .png with a png content type still reports image/jpeg.
    $tmp = tempnam(sys_get_temp_dir(), 'edp').'.png';
    file_put_contents($tmp, Fixtures::jpeg());
    $upload = new UploadedFile($tmp, 'lying.png', 'image/png', null, true);

    processor()->storePrimary($upload, 'photos', 'p/a.webp', new PhotoDefinition(allowedMimeTypes: ['image/png']));
})->throws(InvalidPhotoException::class, 'image/jpeg');

it('rejects a source over the size limit before trying to decode it', function (): void {
    // A deterministic 2 KB payload rather than a real image, whose compressed
    // size would depend on the encoder. It is not decodable either, so the
    // "exceeds" message also proves the size guard runs before the decode.
    $definition = new PhotoDefinition(maxFileSize: 1); // 1 KB

    processor()->storePrimary(str_repeat('x', 2048), 'photos', 'p/a.webp', $definition);
})->throws(InvalidPhotoException::class, 'exceeds');

it('reports an unknown format as a definition problem', function (): void {
    processor()->storePrimary(Fixtures::png(), 'photos', 'p/a.bogus', new PhotoDefinition(format: 'bogus'));
})->throws(PhotoDefinitionException::class, 'bogus');

it('bakes EXIF orientation into the pixels when auto_orient is on', function (): void {
    // The fixture is 60x20 tagged Orientation=6, so orienting swaps the axes.
    processor()->storePrimary(
        __DIR__.'/fixtures/orientation-6.jpg',
        'photos',
        'p/oriented.webp',
        new PhotoDefinition(autoOrient: true),
    );

    expect(storedImage('p/oriented.webp')->width())->toBe(20);
    expect(storedImage('p/oriented.webp')->height())->toBe(60);
})->skip(
    ! extension_loaded('exif') || ! is_file(__DIR__.'/fixtures/orientation-6.jpg'),
    'needs ext-exif and the committed orientation fixture'
);

it('generates a contain conversion that fits inside the box', function (): void {
    $definition = new PhotoDefinition;
    processor()->storePrimary(Fixtures::png(100, 50), 'photos', 'p/a.webp', $definition);

    processor()->storeConversion(
        'photos', 'p/a.webp', 'p/conversions/a-thumb.webp',
        $definition, new Conversion(width: 20, height: 20),
    );

    expect(storedImage('p/conversions/a-thumb.webp')->width())->toBe(20);
    expect(storedImage('p/conversions/a-thumb.webp')->height())->toBe(10);
});

it('generates a cover conversion that fills and crops the box', function (): void {
    $definition = new PhotoDefinition;
    processor()->storePrimary(Fixtures::png(100, 50), 'photos', 'p/a.webp', $definition);

    processor()->storeConversion(
        'photos', 'p/a.webp', 'p/conversions/a-sq.webp',
        $definition, new Conversion(width: 20, height: 20, fit: Fit::Cover),
    );

    expect(storedImage('p/conversions/a-sq.webp')->width())->toBe(20);
    expect(storedImage('p/conversions/a-sq.webp')->height())->toBe(20);
});

it('lets a conversion override the format', function (): void {
    $definition = new PhotoDefinition;
    processor()->storePrimary(Fixtures::png(), 'photos', 'p/a.webp', $definition);

    processor()->storeConversion(
        'photos', 'p/a.webp', 'p/conversions/a-thumb.jpeg',
        $definition, new Conversion(width: 20, format: 'jpeg'),
    );

    expect(storedImage('p/conversions/a-thumb.jpeg')->origin()->mediaType())->toBe('image/jpeg');
});

it('fails clearly when the conversion source is missing', function (): void {
    processor()->storeConversion(
        'photos', 'p/gone.webp', 'p/conversions/gone-thumb.webp',
        new PhotoDefinition, new Conversion(width: 20),
    );
})->throws(InvalidPhotoException::class, 'p/gone.webp');

it('rejects a disallowed container before decoding it, not after', function (): void {
    // The post-decode media-type check cannot be the memory control: under the
    // imagick driver a 100-page TIFF is fully materialised before anything
    // looks at its type — measured at roughly 3 GB from 1.4 MB of input, native
    // allocations that PHP's memory_limit does not bound and that the pixel
    // budget cannot predict (its frame counter reads GIF and WebP only).
    //
    // The assertion pins *which* guard fires. Under the gd driver a TIFF is
    // undecodable, so if this check ran after decode() the failure would read
    // "could not be decoded as an image". Getting the allowlist message
    // instead is only possible from a check that runs first.
    config()->set('eloquent_photo.driver', 'gd');

    expect(fn () => processor()->storePrimary(
        multiPageTiff(2, 40, 30), 'photos', 'p/a.webp', new PhotoDefinition,
    ))->toThrow(InvalidPhotoException::class, 'Images of type [image/tiff] are not allowed.');

    Storage::disk('photos')->assertMissing('p/a.webp');
})->skip(! extension_loaded('imagick'), 'ext-imagick is not installed (needed to build a TIFF)');

it('still accepts a container the allowlist names once the sniff agrees', function (): void {
    // The pre-decode check must not reject the formats the default allowlist
    // does name — getimagesizefromstring() has to agree with the post-decode
    // media type for every one of them, or the guard is a false-rejection bug.
    foreach ([Fixtures::png(), Fixtures::jpeg(), Fixtures::gif(), Fixtures::webp()] as $index => $bytes) {
        processor()->storePrimary($bytes, 'photos', 'p/'.$index.'.webp', new PhotoDefinition);
        Storage::disk('photos')->assertExists('p/'.$index.'.webp');
    }
});

it('lets a container it cannot identify through to the post-decode check', function (): void {
    // getimagesizefromstring() returns false for anything it cannot place, and
    // the pre-decode guard deliberately does not fail closed on that: the
    // source still has to survive decode() and the media-type check there.
    expect(fn () => processor()->storePrimary(
        'this is not an image at all', 'photos', 'p/a.webp', new PhotoDefinition,
    ))->toThrow(InvalidPhotoException::class, 'could not be decoded as an image');
});

it('throws a storage failure when the disk rejects the primary write', function (): void {
    // put() returns false rather than throwing unless the disk opts into
    // exceptions, which the default Laravel filesystems config does not.
    // A partial mock: only put() is stubbed, everything else (get(),
    // assertExists()) still hits the real fake disk.
    $rejectingDisk = Mockery::mock(Storage::disk('photos'));
    $rejectingDisk->shouldReceive('put')->andReturn(false);
    Storage::set('photos', $rejectingDisk);

    processor()->storePrimary(Fixtures::png(), 'photos', 'p/a.webp', new PhotoDefinition);
})->throws(StorageFailedException::class, 'p/a.webp');

it('throws a storage failure when the disk rejects a conversion write', function (): void {
    $definition = new PhotoDefinition;
    processor()->storePrimary(Fixtures::png(100, 50), 'photos', 'p/a.webp', $definition);

    $rejectingDisk = Mockery::mock(Storage::disk('photos'));
    $rejectingDisk->shouldReceive('put')->andReturn(false);
    Storage::set('photos', $rejectingDisk);

    processor()->storeConversion(
        'photos', 'p/a.webp', 'p/conversions/a-thumb.webp',
        $definition, new Conversion(width: 20, height: 20),
    );
})->throws(StorageFailedException::class, 'p/conversions/a-thumb.webp');

/**
 * An N-page LZW TIFF, every page identical. Built with imagick directly: no GD
 * build can write TIFF at all, and the point of the fixture is a container the
 * pixel-budget guard's frame counter does not understand — it reads GIF and
 * WebP markers only, so a 100-page TIFF measures as one frame.
 */
function multiPageTiff(int $pages, int $width, int $height): string
{
    $document = new Imagick;

    for ($page = 0; $page < $pages; $page++) {
        $image = new Imagick;
        $image->newImage($width, $height, 'red');
        $image->setImageFormat('tiff');
        $image->setImageCompression(Imagick::COMPRESSION_LZW);
        $document->addImage($image);
    }

    $document->setImageFormat('tiff');

    return $document->getImagesBlob();
}
