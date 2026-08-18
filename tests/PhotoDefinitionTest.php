<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;

it('falls back to package config for every omitted argument', function (): void {
    $definition = new PhotoDefinition;

    expect($definition->disk)->toBe('photos');           // set by TestCase
    expect($definition->format)->toBe('webp');
    expect($definition->quality)->toBe(75);
    expect($definition->nameAttribute)->toBe('slug');
    expect($definition->nameLimit)->toBe(240);
    expect($definition->timestampFormat)->toBe('U');
    expect($definition->maxFileSize)->toBe(10240);
    expect($definition->maxWidth)->toBe(2560);
    expect($definition->autoOrient)->toBeTrue();
    expect($definition->queueConversions)->toBeFalse();
    expect($definition->conversions)->toBe([]);
    expect($definition->directory)->toBeNull();
});

it('prefers explicit arguments over config', function (): void {
    $definition = new PhotoDefinition(
        disk: 's3',
        directory: 'avatars',
        format: 'jpeg',
        quality: 40,
        queueConversions: true,
    );

    expect($definition->disk)->toBe('s3');
    expect($definition->directory)->toBe('avatars');
    expect($definition->format)->toBe('jpeg');
    expect($definition->quality)->toBe(40);
    expect($definition->queueConversions)->toBeTrue();
});

it('honours a false boolean argument instead of falling through to config', function (): void {
    config()->set('eloquent_photo.auto_orient', true);

    expect((new PhotoDefinition(autoOrient: false))->autoOrient)->toBeFalse();
});

it('exposes conversions by name', function (): void {
    $thumb = new Conversion(width: 100);
    $definition = new PhotoDefinition(conversions: ['thumb' => $thumb]);

    expect($definition->conversion('thumb'))->toBe($thumb);
});

it('rejects an unknown conversion name', function (): void {
    (new PhotoDefinition(conversions: ['thumb' => new Conversion(width: 100)]))
        ->conversion('massive');
})->throws(PhotoDefinitionException::class, 'No conversion named [massive]');

it('rejects a conversions array holding something other than Conversion objects', function (): void {
    // @phpstan-ignore argument.type (deliberately wrong element type, to exercise the runtime guard)
    new PhotoDefinition(conversions: ['thumb' => 100]);
})->throws(PhotoDefinitionException::class, 'must be a Conversion');

it('accepts an env()-shaped numeric string for an integer config value', function (): void {
    config()->set('eloquent_photo.quality', '80');

    expect((new PhotoDefinition)->quality)->toBe(80);
});

it('still rejects an ambiguous numeric string for an integer config value', function (): void {
    config()->set('eloquent_photo.quality', '80.5');

    new PhotoDefinition;
})->throws(PhotoDefinitionException::class, 'must be an integer');

it('accepts an env()-shaped booleanish string for a boolean config value', function (): void {
    config()->set('eloquent_photo.queue_conversions', '1');

    expect((new PhotoDefinition)->queueConversions)->toBeTrue();
});

it('still rejects an unrecognised string for a boolean config value', function (): void {
    config()->set('eloquent_photo.queue_conversions', 'banana');

    new PhotoDefinition;
})->throws(PhotoDefinitionException::class, 'must be a boolean');

it('rejects a non-list allowed_mime_types config value', function (): void {
    config()->set('eloquent_photo.allowed_mime_types', ['a' => 'image/png']);

    new PhotoDefinition;
})->throws(PhotoDefinitionException::class, 'must be a list of strings');

it('rejects a directory override containing a traversal segment', function (): void {
    new PhotoDefinition(directory: 'tenants/../../../etc');
})->throws(PhotoDefinitionException::class, 'tenants/../../../etc');

it('rejects a directory override that is entirely a traversal segment', function (): void {
    new PhotoDefinition(directory: '../etc');
})->throws(PhotoDefinitionException::class, '../etc');

it('rejects a directory override containing a current-directory segment', function (): void {
    new PhotoDefinition(directory: 'a/./b');
})->throws(PhotoDefinitionException::class, 'a/./b');

it('rejects a directory override containing a Windows-style traversal segment', function (): void {
    new PhotoDefinition(directory: 'tenants\\..\\etc');
})->throws(PhotoDefinitionException::class, 'tenants\..\etc');

it('accepts a legitimate multi-segment directory override', function (): void {
    expect((new PhotoDefinition(directory: 'tenants/acme'))->directory)->toBe('tenants/acme');
});
