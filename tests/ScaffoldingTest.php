<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;

it('boots the package and merges its config', function (): void {
    expect(config('eloquent_photo.format'))->toBe('webp');
    expect(config('eloquent_photo.quality'))->toBe(75);
    expect(config('eloquent_photo.allowed_mime_types'))->not->toContain('image/svg+xml');
});

it('has a working in-memory database', function (): void {
    $post = Post::create(['slug' => 'hello-world']);

    expect($post->exists)->toBeTrue();
    expect(Post::query()->count())->toBe(1);
});
