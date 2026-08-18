<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\SoftPost;
use Illuminate\Support\Facades\DB;
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

it('coerces an env()-shaped config value the same as every other boolean flag', function (bool|string $value, bool $expectDeleted): void {
    // Every other boolean in PhotoDefinition goes through configBool(), which
    // accepts env()-shaped strings since Laravel's env() leaves numeric/bool
    // strings as strings. This flag has no PhotoDefinition property of its
    // own (it is package-wide, not per-field) but must be coerced the same
    // way — an inline `!== true` check would silently disable the whole
    // hook for a config reading env('...', true), with no error.
    config()->set('eloquent_photo.delete_files_on_model_delete', $value);

    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $primary = deletedPrimaryPath($post);

    $post->delete();

    if ($expectDeleted) {
        Storage::disk('photos')->assertMissing($primary);
    } else {
        Storage::disk('photos')->assertExists($primary);
    }
})->with([
    'string "true"' => ['true', true],
    'string "1"' => ['1', true],
    'string "false"' => ['false', false],
    'string "0"' => ['0', false],
    'bool true' => [true, true],
    'bool false' => [false, false],
]);

it('does not fall over deleting a model with no photos stored', function (): void {
    Post::create(['slug' => 'a'])->delete();
})->throwsNoExceptions();

it('keeps the files when the deleting transaction rolls back', function (): void {
    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $primary = deletedPrimaryPath($post);

    try {
        DB::transaction(function () use ($post): void {
            $post->delete();

            throw new RuntimeException('rolled back on purpose');
        });
    } catch (RuntimeException) {
        // Expected — see above.
    }

    expect(Post::query()->find($post->getKey()))->not->toBeNull();
    Storage::disk('photos')->assertExists($primary);
});

it('deletes the files once the deleting transaction commits', function (): void {
    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $primary = deletedPrimaryPath($post);

    DB::transaction(function () use ($post): void {
        $post->delete();
    });

    expect(Post::query()->find($post->getKey()))->toBeNull();
    Storage::disk('photos')->assertMissing($primary);
});

it('keeps the files when a soft delete commits inside a transaction', function (): void {
    $post = SoftPost::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(), 'photo');
    $primary = deletedPrimaryPath($post);

    DB::transaction(function () use ($post): void {
        $post->delete();
    });

    expect($post->trashed())->toBeTrue();
    Storage::disk('photos')->assertExists($primary);
});
