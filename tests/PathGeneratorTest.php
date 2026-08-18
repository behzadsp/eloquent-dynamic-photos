<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\PathGenerator;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\Tests\Support\Post;

// A plain typed helper instead of a beforeEach() `$this->generator` fixture:
// Pest binds `$this` in it()/beforeEach() closures to the real TestCase at
// runtime, but phpstan only sees it as Pest\PendingCalls\TestCall, which has
// no way to know about a property assigned ad hoc. A helper needs no
// suppression and stays fully typed.
function generator(): PathGenerator
{
    return new PathGenerator('images');
}

it('slugifies the name and never appends an ellipsis', function (): void {
    // Str::limit defaults its terminator to "...", which v1 leaked into filenames.
    expect(generator()->sanitize(str_repeat('a', 300), 10))->toBe(str_repeat('a', 10));
});

it('strips path traversal out of the name attribute', function (): void {
    expect(generator()->sanitize('../../etc/passwd', 240))->toBe('etcpasswd');
    expect(generator()->sanitize('a/b/c', 240))->toBe('abc');
});

it('falls back when slugifying leaves nothing behind', function (): void {
    // Str::slug() reduces an all-CJK string to "".
    expect(generator()->sanitize('日本語', 240, '42'))->toBe('42');
});

it('generates a random name when even the fallback slugifies to nothing', function (): void {
    expect(generator()->sanitize('日本語', 240, '中文'))->toMatch('/^[A-Za-z0-9]{8}$/');
});

it('never leaves a trailing separator after truncating', function (): void {
    expect(generator()->sanitize('one-two-three', 4))->toBe('one');
});

it('joins the configured root with the pluralised model name', function (): void {
    expect(generator()->prefix(new Post, new PhotoDefinition))->toBe('images/posts');
});

it('lets a definition override the whole prefix', function (): void {
    $definition = new PhotoDefinition(directory: '/avatars/');

    expect(generator()->prefix(new Post, $definition))->toBe('avatars');
});

it('builds a primary path from prefix, name, randomness and timestamp', function (): void {
    $post = new Post(['slug' => 'My Post Title!']);

    expect(generator()->primaryPath($post, new PhotoDefinition))
        ->toMatch('#^images/posts/my-post-title_[A-Za-z0-9]{5}_\d+\.webp$#');
});

it('uses the model key when the name attribute is null', function (): void {
    $post = new Post(['slug' => null]);
    $post->setAttribute('id', 7);

    expect(generator()->primaryPath($post, new PhotoDefinition))
        ->toMatch('#^images/posts/7_[A-Za-z0-9]{5}_\d+\.webp$#');
});

it('derives conversion paths from the stored primary path', function (): void {
    expect(generator()->conversionPath('images/posts/a-post_ab12x_1723.webp', 'thumb', 'jpeg'))
        ->toBe('images/posts/conversions/a-post_ab12x_1723-thumb.jpeg');
});

it('derives conversion paths for a primary stored at the disk root', function (): void {
    expect(generator()->conversionPath('a-post.webp', 'thumb', 'webp'))
        ->toBe('conversions/a-post-thumb.webp');
});

it('cannot be used to make prefix() emit a directory traversal segment', function (): void {
    // prefix() itself never sanitizes $definition->directory — a slash is a
    // legitimate separator there. The guard lives in PhotoDefinition's
    // constructor instead, so the unsafe value never reaches prefix() at all.
    expect(fn () => new PhotoDefinition(directory: 'tenants/../../../etc'))
        ->toThrow(PhotoDefinitionException::class);
});
