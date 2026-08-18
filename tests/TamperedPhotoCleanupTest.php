<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Exceptions\InvalidPhotoException;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Fixtures;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('photos');
});

dataset('tampered columns', [
    'a traversal segment' => ['../../../etc/passwd'],
    'a null byte' => ["images/posts/a\0.webp"],
]);

/**
 * eloquentPhotoRawPath() rightly throws on these values for every read path
 * (photoPath/photoUrl/photoFullPath); it must never hand back a path that
 * could escape the disk root. But before this fix, updatePhoto(), deletePhoto()
 * and deletePhotoFiles() read the column through that same throwing guard, so
 * a row corrupted this way (e.g. via $guarded = [] plus mass assignment) could
 * not be fixed through this package's API at all — only raw SQL could recover
 * it. These assert the write and cleanup methods now treat a tampered value
 * as "nothing safe to act on" rather than bricking the row, while the read
 * guard keeps refusing to hand the path back.
 */
it('lets updatePhoto overwrite a tampered column instead of throwing', function (string $tampered): void {
    $post = Post::create(['slug' => 'my-post', 'photo' => $tampered]);

    $post->updatePhoto(Fixtures::png(), 'photo');

    // Succeeding at all proves no I/O was attempted against the tampered
    // path: Flysystem's local adapter throws on real I/O with a traversal
    // segment (that's the failure mode this guard exists to prevent).
    expect($post->photo)->toMatch('#^images/posts/my-post_[A-Za-z0-9]{5}_\d+\.webp$#');
})->with('tampered columns');

it('lets deletePhoto clear a tampered column instead of throwing', function (string $tampered): void {
    $post = Post::create(['slug' => 'my-post', 'photo' => $tampered]);

    expect($post->deletePhoto('photo'))->toBeFalse();

    $fresh = $post->fresh();

    if ($fresh === null) {
        throw new RuntimeException('Expected the post to still exist.');
    }

    expect($fresh->photo)->toBeNull();
})->with('tampered columns');

it('lets deletePhotoFiles report false on a tampered column instead of throwing', function (string $tampered): void {
    $post = Post::create(['slug' => 'my-post', 'photo' => $tampered]);

    expect($post->deletePhotoFiles('photo'))->toBeFalse();
})->with('tampered columns');

it('still refuses to hand back a tampered path for reading', function (string $tampered): void {
    $post = Post::create(['slug' => 'my-post', 'photo' => $tampered]);

    expect(fn () => $post->photoPath('photo'))->toThrow(InvalidPhotoException::class);
    expect(fn () => $post->photoUrl('photo'))->toThrow(InvalidPhotoException::class);
    expect(fn () => $post->photoFullPath('photo'))->toThrow(InvalidPhotoException::class);
})->with('tampered columns');
