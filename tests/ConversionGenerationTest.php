<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

beforeEach(function (): void {
    Storage::fake('photos');
});

/**
 * Mirrors storedImage() in PhotoProcessorTest.php: Storage::get() is
 * nullable, but this is only ever called right after assertExists() confirms
 * the file is there.
 */
function storedThumb(string $path): ImageInterface
{
    $binary = Storage::disk('photos')->get($path);

    if ($binary === null) {
        throw new RuntimeException("Fixture [{$path}] does not exist on the fake disk.");
    }

    return app(ImageManager::class)->decodeBinary($binary);
}

/**
 * photoPath() is nullable per its signature, but every call site below is
 * immediately after storing the photo the thumb conversion derives from.
 * Narrows the type for the storage assertions rather than widening or
 * asserting it away, matching the storedPath()/freshOrFail() helpers in
 * UpdatePhotoTest.php.
 */
function thumbPath(Post $post): string
{
    return $post->photoPath('photo', 'thumb')
        ?? throw new RuntimeException('Expected the post to have a thumb conversion path.');
}

it('generates declared conversions during updatePhoto', function (): void {
    $post = Post::create(['slug' => 'my-post']);

    $post->updatePhoto(Fixtures::png(100, 50), 'photo');

    $thumb = thumbPath($post);
    Storage::disk('photos')->assertExists($thumb);

    $image = storedThumb($thumb);
    expect($image->width())->toBe(20);
    expect($image->height())->toBe(20);
});

it('writes no conversions for a field that declares none', function (): void {
    $post = Post::create(['slug' => 'my-post']);

    $post->updatePhoto(Fixtures::png(), 'banner');

    expect(Storage::disk('photos')->allFiles('banners/conversions'))->toBe([]);
});

it('removes conversions when the photo is replaced', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $oldThumb = thumbPath($post);

    $post->updatePhoto(Fixtures::png(100, 50), 'photo');

    Storage::disk('photos')->assertMissing($oldThumb);
    Storage::disk('photos')->assertExists(thumbPath($post));
});

it('removes conversions when the photo is deleted', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $thumb = thumbPath($post);

    $post->deletePhoto('photo');

    Storage::disk('photos')->assertMissing($thumb);
});

it('backfills conversions for a photo stored before one was declared', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $thumb = thumbPath($post);
    Storage::disk('photos')->delete($thumb);
    Storage::disk('photos')->assertMissing($thumb);

    $post->regeneratePhotoConversions('photo');

    Storage::disk('photos')->assertExists($thumb);
});

it('does nothing when regenerating a field with no stored photo', function (): void {
    Post::create(['slug' => 'my-post'])->regeneratePhotoConversions('photo');

    expect(Storage::disk('photos')->allFiles())->toBe([]);
});
