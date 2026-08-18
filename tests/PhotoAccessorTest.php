<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Exceptions\InvalidPhotoException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Exceptions\UnsupportedDiskOperation;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');
});

it('returns null paths and urls when the column is empty', function (): void {
    $post = Post::create(['slug' => 'a']);

    expect($post->photoPath('photo'))->toBeNull();
    expect($post->photoUrl('photo'))->toBeNull();
    expect($post->photo_url)->toBeNull();
});

it('exposes the stored path and its url', function (): void {
    $post = Post::create(['slug' => 'a', 'photo' => 'images/posts/a_x_1.webp']);

    expect($post->photoPath('photo'))->toBe('images/posts/a_x_1.webp');
    expect($post->photoUrl('photo'))->toBe(Storage::disk('photos')->url('images/posts/a_x_1.webp'));
    expect($post->photo_url)->toBe($post->photoUrl('photo'));
});

it('derives conversion paths and urls', function (): void {
    $post = Post::create(['slug' => 'a', 'photo' => 'images/posts/a_x_1.webp']);

    expect($post->photoPath('photo', 'thumb'))->toBe('images/posts/conversions/a_x_1/thumb.webp');
    expect($post->photoUrl('photo', 'thumb'))
        ->toBe(Storage::disk('photos')->url('images/posts/conversions/a_x_1/thumb.webp'));
});

it('rejects a conversion name that was never declared', function (): void {
    Post::create(['slug' => 'a', 'photo' => 'images/posts/a_x_1.webp'])->photoPath('photo', 'huge');
})->throws(PhotoDefinitionException::class, 'No conversion named [huge]');

it('rejects a field that was never declared', function (): void {
    Post::create(['slug' => 'a'])->photoPath('title');
})->throws(PhotoDefinitionException::class, 'not declared');

it('does not hijack a real column that happens to end in _url', function (): void {
    // The v1 accessor fired for any attribute pair, so website_url silently
    // returned Storage::url($website).
    $post = Post::create([
        'slug' => 'a',
        'website' => 'example.com',
        'website_url' => 'https://example.com',
    ]);

    expect($post->website_url)->toBe('https://example.com');
});

it('strips only the _url suffix, not every occurrence', function (): void {
    // v1 used str_replace('_url', '', $key), so url_photo_url became "_photo".
    $post = Post::create(['slug' => 'a', 'photo' => 'images/posts/a_x_1.webp']);

    expect($post->getAttribute('url_photo_url'))->toBeNull();
});

it('leaves ordinary attributes alone', function (): void {
    $post = Post::create(['slug' => 'a', 'title' => 'Hello']);

    expect($post->title)->toBe('Hello');
    expect($post->slug)->toBe('a');
});

it('resolves a full local path', function (): void {
    $post = Post::create(['slug' => 'a', 'photo' => 'images/posts/a_x_1.webp']);

    expect($post->photoFullPath('photo'))
        ->toBe(Storage::disk('photos')->path('images/posts/a_x_1.webp'));
});

it('refuses a full path on a disk whose adapter is not local', function (): void {
    // Storage::fake() always installs a local adapter, so it can never produce
    // a "resolves, but isn't local" disk to test against, and
    // league/flysystem-aws-s3-v3 isn't installed to build a real S3 one.
    // Storage::extend() lets a custom driver return any Filesystem
    // implementation; a Mockery double of the contract is one that is
    // provably not an Illuminate\Filesystem\FilesystemAdapter, which is
    // exactly the branch this guard needs to exercise.
    Storage::extend('fake-remote', fn () => Mockery::mock(Filesystem::class));
    config()->set('filesystems.disks.remote', ['driver' => 'fake-remote']);
    config()->set('eloquent_photo.disk', 'remote');

    Post::create(['slug' => 'a', 'photo' => 'a.webp'])->photoFullPath('photo');
})->throws(UnsupportedDiskOperation::class, 'is not a local filesystem');

it('rejects a stored path containing a traversal segment', function (): void {
    // PathGenerator never writes a ".." segment; a stored value that has one
    // was tampered with outside this package (e.g. via $guarded = []).
    $post = new Post(['slug' => 'a']);
    $post->photo = '../../../etc/passwd';

    expect(fn () => $post->photoPath('photo'))->toThrow(InvalidPhotoException::class);
    expect(fn () => $post->photoUrl('photo'))->toThrow(InvalidPhotoException::class);
    expect(fn () => $post->photoFullPath('photo'))->toThrow(InvalidPhotoException::class);
});

it('rejects a stored path containing a null byte', function (): void {
    $post = new Post(['slug' => 'a']);
    $post->photo = "images/posts/a\0.webp";

    expect(fn () => $post->photoPath('photo'))->toThrow(InvalidPhotoException::class);
});

it('leaves a normal stored path unaffected by the tamper guard', function (): void {
    $post = Post::create(['slug' => 'a', 'photo' => 'images/posts/a_x_1.webp']);

    expect($post->photoPath('photo'))->toBe('images/posts/a_x_1.webp');
    expect($post->photoUrl('photo'))->toBe(Storage::disk('photos')->url('images/posts/a_x_1.webp'));
    expect($post->photoFullPath('photo'))->toBe(Storage::disk('photos')->path('images/posts/a_x_1.webp'));
});
