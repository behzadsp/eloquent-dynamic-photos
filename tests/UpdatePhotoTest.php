<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Exceptions\InvalidPhotoException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Illuminate\Support\Facades\DB;
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

it('surfaces the original save failure even when the compensating delete also fails', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $original = storedPath($post);

    // A double over the real fake disk: unstubbed calls (the write below)
    // still hit the real disk, but delete() always fails, simulating the
    // disk itself being unavailable during cleanup.
    $flakyDisk = Mockery::mock(Storage::disk('photos'));
    $flakyDisk->shouldReceive('delete')->andThrow(new RuntimeException('disk unavailable during cleanup'));
    Storage::set('photos', $flakyDisk);

    Post::saving(function (): void {
        throw new RuntimeException('save blew up');
    });

    try {
        // The caller needs to know the save failed, not that cleanup also
        // failed afterwards.
        expect(fn () => $post->updatePhoto(Fixtures::png(), 'photo'))
            ->toThrow(RuntimeException::class, 'save blew up');

        // Still restored, even though the compensating delete above threw.
        expect($post->photo)->toBe($original);
    } finally {
        Post::flushEventListeners();
    }
});

it('does not fail the update when cleaning up the old file fails after a successful save', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $original = storedPath($post);

    $flakyDisk = Mockery::mock(Storage::disk('photos'));
    $flakyDisk->shouldReceive('delete')->andThrow(new RuntimeException('disk unavailable during cleanup'));
    Storage::set('photos', $flakyDisk);

    // The save already succeeded by the time the old file is cleaned up, so a
    // failure here must not turn a successful update into an error.
    $post->updatePhoto(Fixtures::png(), 'photo');

    expect($post->photo)->not->toBe($original);
    Storage::disk('photos')->assertExists(storedPath($post));
});

it('lets a programming error from the old-file cleanup crash rather than swallowing it', function (): void {
    // A disk hiccup (an Exception) during best-effort cleanup is swallowed —
    // see the test above — but an Error (a TypeError, a genuine bug) is not
    // this cleanup's job to hide.
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(), 'photo');

    $brokenDisk = Mockery::mock(Storage::disk('photos'));
    $brokenDisk->shouldReceive('delete')->andThrow(new TypeError('a programming fault'));
    Storage::set('photos', $brokenDisk);

    expect(fn () => $post->updatePhoto(Fixtures::png(), 'photo'))
        ->toThrow(TypeError::class, 'a programming fault');
});

it('keeps the original file when the updating transaction rolls back', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $original = storedPath($post);

    try {
        DB::transaction(function () use ($post): void {
            $post->updatePhoto(Fixtures::png(), 'photo');

            throw new RuntimeException('rolled back on purpose');
        });
    } catch (RuntimeException) {
        // Expected — see above.
    }

    expect(freshOrFail($post)->photo)->toBe($original);
    Storage::disk('photos')->assertExists($original);
});

it('deletes the previous file once the updating transaction commits', function (): void {
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $original = storedPath($post);

    DB::transaction(function () use ($post): void {
        $post->updatePhoto(Fixtures::png(), 'photo');
    });

    Storage::disk('photos')->assertMissing($original);
});
