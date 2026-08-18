<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Fit;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

beforeEach(function (): void {
    Storage::fake('photos');
    Post::$photoDefinitionsOverride = null;
});

afterEach(function (): void {
    Post::$photoDefinitionsOverride = null;
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

/**
 * Model::fresh() is nullable; narrows it so property access and method calls
 * type-check. Also the only way these tests get a Post instance that picks
 * up Post::$photoDefinitionsOverride: an instance already used earlier in
 * the test has its own declarations cached (see
 * HasPhotos::eloquentPhotoDefinitionsCache), so re-declaring the field
 * requires a fresh instance, not the same one.
 */
function freshPost(Post $post): Post
{
    return $post->fresh() ?? throw new RuntimeException('Expected the post to still exist.');
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
    // Each primary owns its own conversions directory: two uploads to the
    // same field never share one, so cleaning up one can never reach into
    // the other's files.
    expect(dirname($oldThumb))->not->toBe(dirname(thumbPath($post)));
});

it('removes every file under the old conversion directory, not just currently declared names', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $thumb = thumbPath($post);
    // Simulates a file left over from a conversion that existed under an
    // earlier declaration and is no longer named "thumb" at all — deleting
    // by declared name would never find this, but deleting the whole
    // directory does.
    $strayFile = dirname($thumb).'/some-other-conversion.webp';
    Storage::disk('photos')->put($strayFile, 'stray bytes');

    $post->updatePhoto(Fixtures::png(100, 50), 'photo');

    Storage::disk('photos')->assertMissing($thumb);
    Storage::disk('photos')->assertMissing($strayFile);
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

it('removes conversion files even after the conversion is no longer declared', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $thumb = thumbPath($post);
    Storage::disk('photos')->assertExists($thumb);

    // Re-declare "photo" with the thumb conversion removed entirely, and
    // reload so the new declaration actually takes effect (see freshPost()).
    Post::$photoDefinitionsOverride = [
        'photo' => new PhotoDefinition,
        'banner' => new PhotoDefinition(format: 'jpeg', directory: 'banners'),
    ];

    freshPost($post)->deletePhoto('photo');

    Storage::disk('photos')->assertMissing($thumb);
});

it('removes the old conversion file even after its format is redeclared', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $oldThumb = thumbPath($post);
    Storage::disk('photos')->assertExists($oldThumb);

    // Re-declare "thumb" with a different format, then upload again. The old
    // file was written under the old format and is no longer reachable by
    // deriving a path from the new declaration.
    Post::$photoDefinitionsOverride = [
        'photo' => new PhotoDefinition(
            conversions: [
                'thumb' => new Conversion(width: 20, height: 20, fit: Fit::Cover, format: 'jpeg'),
            ],
        ),
        'banner' => new PhotoDefinition(format: 'jpeg', directory: 'banners'),
    ];

    $redeclared = freshPost($post);
    $redeclared->updatePhoto(Fixtures::png(100, 50), 'photo');

    Storage::disk('photos')->assertMissing($oldThumb);
    Storage::disk('photos')->assertExists(thumbPath($redeclared));
});
