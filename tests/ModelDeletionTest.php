<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\SoftPost;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');
});

/**
 * The photo column is nullable per the schema, but every call site below is
 * immediately after updatePhoto() has set it. Named differently from
 * storedPath()/deletablePath() elsewhere since Pest loads every test file's
 * top-level functions into one global scope.
 */
function deletedPrimaryPath(Post|SoftPost $model): string
{
    return $model->photo ?? throw new RuntimeException('Expected the model to have a stored photo.');
}

/**
 * photoPath() is nullable per its signature, but every call site below is
 * immediately after updatePhoto() has stored the photo the thumb conversion
 * derives from.
 */
function deletedThumbPath(Post $post): string
{
    return $post->photoPath('photo', 'thumb')
        ?? throw new RuntimeException('Expected the post to have a thumb conversion path.');
}

it('deletes the files when a model is deleted', function (): void {
    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $primary = deletedPrimaryPath($post);
    $thumb = deletedThumbPath($post);

    $post->delete();

    Storage::disk('photos')->assertMissing($primary);
    Storage::disk('photos')->assertMissing($thumb);
});

it('deletes files for every declared field', function (): void {
    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $post->updatePhoto(Fixtures::png(), 'banner');

    $post->delete();

    expect(Storage::disk('photos')->allFiles())->toBe([]);
});

it('keeps the files when a model is only soft deleted', function (): void {
    $post = SoftPost::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $primary = deletedPrimaryPath($post);

    $post->delete();

    expect($post->trashed())->toBeTrue();
    Storage::disk('photos')->assertExists($primary);
});

it('deletes the files when a soft deleted model is force deleted', function (): void {
    $post = SoftPost::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $primary = deletedPrimaryPath($post);

    $post->forceDelete();

    Storage::disk('photos')->assertMissing($primary);
});

it('leaves the files alone when the config flag is off', function (): void {
    config()->set('eloquent_photo.delete_files_on_model_delete', false);

    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $primary = deletedPrimaryPath($post);

    $post->delete();

    Storage::disk('photos')->assertExists($primary);
});

it('does not fall over deleting a model with no photos stored', function (): void {
    Post::create(['slug' => 'a'])->delete();
})->throwsNoExceptions();
