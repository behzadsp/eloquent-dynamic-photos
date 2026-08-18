<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Providers;

use Behzadsp\EloquentDynamicPhotos\PathGenerator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;

class EloquentDynamicPhotosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/eloquent_photo.php', 'eloquent_photo');

        $this->app->singleton(ImageManager::class, static function (Application $app): ImageManagerInterface {
            $driver = $app->make(ConfigRepository::class)->get('eloquent_photo.driver') === 'imagick'
                ? ImagickDriver::class
                : GdDriver::class;

            // v4 replaced v3's ImageManager::gd() helpers with usingDriver().
            return ImageManager::usingDriver($driver);
        });

        $this->app->singleton(PathGenerator::class, static function (Application $app): PathGenerator {
            $directory = $app->make(ConfigRepository::class)->get('eloquent_photo.directory');

            return new PathGenerator(is_string($directory) ? $directory : '');
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/eloquent_photo.php' => config_path('eloquent_photo.php'),
            ], 'eloquent-photo-config');
        }
    }
}
