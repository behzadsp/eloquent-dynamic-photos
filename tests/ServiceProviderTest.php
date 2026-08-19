<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\PathGenerator;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\Providers\EloquentDynamicPhotosServiceProvider;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;

it('binds an ImageManager using the configured driver', function (): void {
    expect(app(ImageManager::class))->toBeInstanceOf(ImageManager::class);
    expect(app(ImageManager::class))->toBe(app(ImageManager::class));
});

it('resolves the gd driver from the shipped config default', function (): void {
    // ImageManager::$driver is public in v4.
    expect(app(ImageManager::class)->driver)->toBeInstanceOf(GdDriver::class);
});

it('resolves the gd driver when explicitly configured', function (): void {
    config()->set('eloquent_photo.driver', 'gd');
    app()->forgetInstance(ImageManager::class);

    expect(app(ImageManager::class)->driver)->toBeInstanceOf(GdDriver::class);
});

it('resolves the imagick driver after the cached instance is forgotten', function (): void {
    config()->set('eloquent_photo.driver', 'imagick');
    app()->forgetInstance(ImageManager::class);

    expect(app(ImageManager::class)->driver)->toBeInstanceOf(ImagickDriver::class);
})->skip(! extension_loaded('imagick'), 'ext-imagick is not installed');

it('rejects an unrecognised driver value', function (): void {
    config()->set('eloquent_photo.driver', 'immagick');
    app()->forgetInstance(ImageManager::class);

    expect(fn () => app(ImageManager::class))
        ->toThrow(PhotoDefinitionException::class, 'Config key [eloquent_photo.driver] must be "gd" or "imagick", "immagick" given.');
});

it('rejects a null driver value', function (): void {
    config()->set('eloquent_photo.driver', null);
    app()->forgetInstance(ImageManager::class);

    expect(fn () => app(ImageManager::class))
        ->toThrow(PhotoDefinitionException::class, 'Config key [eloquent_photo.driver] must be "gd" or "imagick", null given.');
});

it('binds a PathGenerator seeded with the configured root directory', function (): void {
    config()->set('eloquent_photo.directory', 'uploads');
    app()->forgetInstance(PathGenerator::class);

    expect(app(PathGenerator::class)->prefix(new Post, new PhotoDefinition))
        ->toBe('uploads/posts');
});

it('rejects a root directory it cannot read as a string', function (): void {
    // The realistic trigger is env('PHOTO_DIRECTORY') with no default, which
    // returns null. Silently treating that as "" would write every photo to the
    // disk root instead of erroring, so it goes through the same narrowing as
    // every other config key rather than an inline is_string() fallback.
    config()->set('eloquent_photo.directory', null);
    app()->forgetInstance(PathGenerator::class);

    expect(fn () => app(PathGenerator::class))
        ->toThrow(PhotoDefinitionException::class, 'Config key [eloquent_photo.directory] must be a string, null given.');
});

it('publishes its config under a namespaced tag', function (): void {
    expect(array_keys(ServiceProvider::pathsToPublish(
        EloquentDynamicPhotosServiceProvider::class,
        'eloquent-photo-config'
    )))->toHaveCount(1);
});
