<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');
});

/**
 * A helper function rather than a `beforeEach` assignment to `$this->post`:
 * phpstan types `$this` inside a pest closure as `Pest\PendingCalls\TestCall`,
 * so assigning to an arbitrary property on it fails level max.
 */
function postWithPhoto(): Post
{
    $post = Post::create(['slug' => 'my-post']);
    $post->updatePhoto(Fixtures::png(), 'photo');

    return $post;
}

/**
 * Post::photo is nullable per its docblock, but by this point in each test a
 * photo has just been stored. Narrows the type for the storage assertions
 * below rather than widening or asserting it away.
 */
function deletablePath(Post $post): string
{
    return $post->photo ?? throw new RuntimeException('Expected the post to have a stored photo path.');
}

/**
 * Model::fresh() is nullable; narrows it so property access type-checks.
 */
function freshPostOrFail(Post $post): Post
{
    return $post->fresh() ?? throw new RuntimeException('Expected the post to still exist.');
}

it('deletes the file but leaves the column alone', function (): void {
    $post = postWithPhoto();
    $path = deletablePath($post);

    expect($post->deletePhotoFiles('photo'))->toBeTrue();

    Storage::disk('photos')->assertMissing($path);
    expect(freshPostOrFail($post)->photo)->toBe($path);
});

it('deletes the file and clears the column', function (): void {
    $post = postWithPhoto();
    $path = deletablePath($post);

    expect($post->deletePhoto('photo'))->toBeTrue();

    Storage::disk('photos')->assertMissing($path);
    expect(freshPostOrFail($post)->photo)->toBeNull();
});

it('reports false when there is nothing to delete', function (): void {
    $post = postWithPhoto();
    $post->deletePhoto('photo');

    expect($post->deletePhoto('photo'))->toBeFalse();
    expect($post->deletePhotoFiles('banner'))->toBeFalse();
});

it('leaves the file and column alone when the save fails', function (): void {
    // v1's dead-pointer bug, on the delete path: deleting the file before
    // saving would leave the column pointing at a file that no longer
    // existed if the save then failed.
    $post = postWithPhoto();
    $path = deletablePath($post);

    Post::saving(function (): void {
        throw new RuntimeException('save blew up');
    });

    try {
        expect(fn () => $post->deletePhoto('photo'))
            ->toThrow(RuntimeException::class, 'save blew up');

        Storage::disk('photos')->assertExists($path);
        expect(freshPostOrFail($post)->photo)->toBe($path);
        // The in-memory column must be restored too, not just the DB row:
        // otherwise a caller that catches the exception and calls save()
        // again would persist null without ever deleting the file, orphaning
        // it. Mirrors updatePhoto()'s equivalent restoration on save failure.
        expect($post->photo)->toBe($path);
    } finally {
        Post::flushEventListeners();
    }
});

it('lets a programming error from the file cleanup crash rather than swallowing it', function (): void {
    // A disk hiccup (an Exception) after the column is already cleared and
    // saved is swallowed — reported as false — but an Error (a TypeError, a
    // genuine bug) is not this cleanup's job to hide.
    $post = postWithPhoto();

    $brokenDisk = Mockery::mock(Storage::disk('photos'));
    $brokenDisk->shouldReceive('delete')->andThrow(new TypeError('a programming fault'));
    Storage::set('photos', $brokenDisk);

    expect(fn () => $post->deletePhoto('photo'))
        ->toThrow(TypeError::class, 'a programming fault');
});

it('keeps the file intact when the deleting transaction rolls back', function (): void {
    $post = postWithPhoto();
    $path = deletablePath($post);

    try {
        DB::transaction(function () use ($post): void {
            $post->deletePhoto('photo');

            throw new RuntimeException('rolled back on purpose');
        });
    } catch (RuntimeException) {
        // Expected — see above.
    }

    expect(freshPostOrFail($post)->photo)->toBe($path);
    Storage::disk('photos')->assertExists($path);
});

it('clears the column and deletes the file once the deleting transaction commits', function (): void {
    $post = postWithPhoto();
    $path = deletablePath($post);

    DB::transaction(function () use ($post): void {
        $post->deletePhoto('photo');
    });

    expect(freshPostOrFail($post)->photo)->toBeNull();
    Storage::disk('photos')->assertMissing($path);
});
