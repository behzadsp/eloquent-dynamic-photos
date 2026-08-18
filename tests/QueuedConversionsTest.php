<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Jobs\ProcessConversionsJob;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\SoftPost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');
});

/**
 * photoPath() is nullable per its signature, but every call site below is
 * immediately after updatePhoto() has stored the photo the thumb conversion
 * derives from. Narrows the type for the storage assertions rather than
 * widening or asserting it away, matching the thumbPath() helper in
 * ConversionGenerationTest.php (named differently here since Pest loads every
 * test file's top-level functions into one global scope).
 */
function queuedThumbPath(Post $post): string
{
    return $post->photoPath('photo', 'thumb')
        ?? throw new RuntimeException('Expected the post to have a thumb conversion path.');
}

/**
 * The photo column is nullable per the schema, but every call site below is
 * immediately after updatePhoto() has set it.
 */
function queuedPrimaryPath(Post $post): string
{
    return $post->photo ?? throw new RuntimeException('Expected the post to have a stored photo.');
}

/**
 * getKey() is declared to return mixed, but ProcessConversionsJob needs an
 * int|string identifier. Every model here has an auto-incrementing integer
 * primary key, so this narrows rather than trusts a cast. Takes any Model
 * (not just Post) since it is also used with SoftPost below.
 */
function queuedModelKey(Model $model): int|string
{
    $key = $model->getKey();

    return is_int($key) || is_string($key)
        ? $key
        : throw new RuntimeException('Expected the model to have an int or string key.');
}

it('generates conversions inline when queueing is off', function (): void {
    Bus::fake();

    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');

    Bus::assertNotDispatched(ProcessConversionsJob::class);
    // Asserting the absence of a job is not enough — the work must still happen.
    Storage::disk('photos')->assertExists(queuedThumbPath($post));
});

it('dispatches a job instead when queueing is on', function (): void {
    Bus::fake();
    config()->set('eloquent_photo.queue_conversions', true);

    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');

    Bus::assertDispatched(
        ProcessConversionsJob::class,
        fn (ProcessConversionsJob $job): bool => $job->modelClass === Post::class
            && $job->modelKey === $post->getKey()
            && $job->field === 'photo'
            && $job->dispatchedPath === $post->photo
    );

    // The primary is written synchronously; only conversions are deferred.
    Storage::disk('photos')->assertExists(queuedPrimaryPath($post));
    Storage::disk('photos')->assertMissing(queuedThumbPath($post));
});

it('routes the job to the configured connection and queue', function (): void {
    Bus::fake();
    config()->set('eloquent_photo.queue_conversions', true);
    config()->set('eloquent_photo.queue.name', 'images');

    Post::create(['slug' => 'a'])->updatePhoto(Fixtures::png(), 'photo');

    Bus::assertDispatched(
        ProcessConversionsJob::class,
        fn (ProcessConversionsJob $job): bool => $job->queue === 'images'
    );
});

it('waits for the transaction to commit', function (): void {
    expect((new ProcessConversionsJob(Post::class, 1, 'photo', 'irrelevant.webp'))->afterCommit)->toBeTrue();
});

it('generates the conversions when the job runs', function (): void {
    config()->set('eloquent_photo.queue_conversions', true);
    Bus::fake();

    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $thumb = queuedThumbPath($post);
    Storage::disk('photos')->assertMissing($thumb);

    (new ProcessConversionsJob(Post::class, queuedModelKey($post), 'photo', queuedPrimaryPath($post)))->handle();

    Storage::disk('photos')->assertExists($thumb);
});

it('does nothing when the row has since been deleted', function (): void {
    (new ProcessConversionsJob(Post::class, 99999, 'photo', 'irrelevant.webp'))->handle();
})->throwsNoExceptions();

it('does nothing when a newer upload has superseded the photo it was dispatched for', function (): void {
    Bus::fake();
    config()->set('eloquent_photo.queue_conversions', true);

    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $stalePath = queuedPrimaryPath($post);
    $staleThumb = queuedThumbPath($post);

    // A second upload lands — and dispatches its own job — before the first
    // job runs, replacing the column out from under it.
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $freshThumb = queuedThumbPath($post);

    (new ProcessConversionsJob(Post::class, queuedModelKey($post), 'photo', $stalePath))->handle();

    Storage::disk('photos')->assertMissing($staleThumb);
    Storage::disk('photos')->assertMissing($freshThumb);
});

it('lets regeneratePhotoConversions still regenerate unconditionally, regardless of any dispatched path', function (): void {
    $post = Post::create(['slug' => 'a']);
    $post->updatePhoto(Fixtures::png(100, 50), 'photo');
    $thumb = queuedThumbPath($post);
    Storage::disk('photos')->delete($thumb);
    Storage::disk('photos')->assertMissing($thumb);

    $post->regeneratePhotoConversions('photo');

    Storage::disk('photos')->assertExists($thumb);
});

it('throws when dispatched for a model class that does not use HasPhotos', function (): void {
    // The column must match $dispatchedPath, or the superseded-upload check
    // above would return quietly first and this would never reach the
    // method_exists() check this test is for.
    $softPost = SoftPost::create(['slug' => 'a', 'photo' => 'irrelevant.webp']);

    expect(fn () => (new ProcessConversionsJob(
        SoftPost::class,
        queuedModelKey($softPost),
        'photo',
        'irrelevant.webp'
    ))->handle())->toThrow(PhotoDefinitionException::class);
});
