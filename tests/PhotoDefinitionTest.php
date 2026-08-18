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
