<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Exceptions\InvalidPhotoException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');
});

/**
 * Post::photo is nullable per its docblock, but by this point in each test a
 * photo has just been stored. Narrows the type for the storage assertions
 * below rather than widening or asserting it away.
 */
function storedPath(Post $post): string
{
    return $post->photo ?? throw new RuntimeException('Expected the post to have a stored photo path.');
}

/**
 * Model::fresh() is nullable; narrows it so property access type-checks.
 */
function freshOrFail(Post $post): Post
{
    return $post->fresh() ?? throw new RuntimeException('Expected the post to still exist.');
}

it('stores the photo and records its path on the model', function (): void {
    $post = Post::create(['slug' => 'my-post']);

    $post->updatePhoto(Fixtures::png(), 'photo');

    expect($post->photo)->toMatch('#^images/posts/my-post_[A-Za-z0-9]{5}_\d+\.webp$#');
    Storage::disk('photos')->assertExists(storedPath($post));
    expect(freshOrFail($post)->photo)->toBe($post->photo);
});

it('honours a per-field format and directory override', function (): void {
    $post = Post::create(['slug' => 'my-post']);

    $post->updatePhoto(Fixtures::png(), 'banner');

    expect($post->banner)->toMatch('#^banners/my-post_[A-Za-z0-9]{5}_\d+\.jpeg$#');
});

it('deletes the previous file only after the new one is saved', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $first = storedPath($post);

    $post->updatePhoto(Fixtures::png(), 'photo');

    expect($post->photo)->not->toBe($first);
    Storage::disk('photos')->assertExists(storedPath($post));
    Storage::disk('photos')->assertMissing($first);
});

it('keeps the old file and column when the new source is invalid', function (): void {
    // v1 deleted the old file first, so a failure here left the column pointing
    // at a file that no longer existed.
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $original = storedPath($post);

    expect(fn () => $post->updatePhoto('not an image', 'photo'))
        ->toThrow(InvalidPhotoException::class);

    expect(freshOrFail($post)->photo)->toBe($original);
    Storage::disk('photos')->assertExists($original);
});

it('cleans up the written file when the save fails', function (): void {
    $post = Post::create(['slug' => 'my-post']);

    // Make save() fail after the file has been written. The listener is global,
    // so it must be flushed before any later test runs.
    Post::saving(function (): void {
        throw new RuntimeException('save blew up');
    });

    try {
        expect(fn () => $post->updatePhoto(Fixtures::png(), 'photo'))
            ->toThrow(RuntimeException::class, 'save blew up');

        // The file written before the save is gone, and the in-memory column is
        // back to what it was.
        expect(Storage::disk('photos')->allFiles())->toBe([]);
        expect($post->photo)->toBeNull();
    } finally {
        Post::flushEventListeners();
    }
});

it('refuses an undeclared field', function (): void {
    Post::create(['slug' => 'a'])->updatePhoto(Fixtures::png(), 'title');
})->throws(PhotoDefinitionException::class, 'not declared');
