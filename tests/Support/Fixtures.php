<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Tests\Support;

use Intervention\Image\Format;
use Intervention\Image\ImageManager;

/**
 * Real encoded image bytes for test input. Generated rather than committed:
 * the tests need valid image data, not any particular picture.
 */
final class Fixtures
{
    public static function png(int $width = 40, int $height = 30): string
    {
        return self::encode($width, $height, 'png');
    }

    public static function jpeg(int $width = 40, int $height = 30): string
    {
        return self::encode($width, $height, 'jpeg');
    }

    private static function encode(int $width, int $height, string $format): string
    {
        return app(ImageManager::class)
            ->createImage($width, $height)
            ->encode(Format::create($format)->encoder(quality: 90))
            ->toString();
    }
}
