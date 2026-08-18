<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Exceptions\InvalidPhotoException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Fit;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\PhotoProcessor;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

beforeEach(function (): void {
    Storage::fake('photos');
});

function processor(): PhotoProcessor
{
    return app(PhotoProcessor::class);
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
