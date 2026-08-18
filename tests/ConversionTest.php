<?php

declare(strict_types=1);

use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Fit;

it('defaults to a contain fit and inherits format and quality', function (): void {
    $conversion = new Conversion(width: 200);

    expect($conversion->width)->toBe(200);
    expect($conversion->height)->toBeNull();
    expect($conversion->fit)->toBe(Fit::Contain);
    expect($conversion->format)->toBeNull();
    expect($conversion->quality)->toBeNull();
});

it('rejects a conversion with no dimensions at all', function (): void {
    new Conversion;
})->throws(PhotoDefinitionException::class, 'needs a width, a height, or both');

it('rejects cover and stretch fits that are missing an axis', function (Fit $fit): void {
    new Conversion(width: 200, fit: $fit);
})->with([[Fit::Cover], [Fit::Stretch]])
    ->throws(PhotoDefinitionException::class, 'needs both a width and a height');

it('accepts cover when both axes are given', function (): void {
    $conversion = new Conversion(width: 200, height: 100, fit: Fit::Cover, format: 'jpeg', quality: 60);

    expect($conversion->fit)->toBe(Fit::Cover);
    expect($conversion->format)->toBe('jpeg');
    expect($conversion->quality)->toBe(60);
});

it('rejects an unsafe format override', function (): void {
    new Conversion(width: 100, format: '../x');
})->throws(PhotoDefinitionException::class, '../x');
