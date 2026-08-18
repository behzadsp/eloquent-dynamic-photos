<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
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
    } finally {
        Post::flushEventListeners();
    }
});
