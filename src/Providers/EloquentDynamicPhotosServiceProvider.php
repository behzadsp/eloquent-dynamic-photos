<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Providers;

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\PathGenerator;
use Behzadsp\EloquentDynamicPhotos\PhotoProcessor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
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

        // The driver is resolved once, at first use, and cached for the life of the
        // container as a singleton. A long-running worker (Octane, queue workers)
        // that reconfigures eloquent_photo.driver per request or per tenant must
        // call forgetInstance() itself to pick up the change.
        $this->app->singleton(ImageManager::class, static function (Application $app): ImageManagerInterface {
            $configured = $app->make(ConfigRepository::class)->get('eloquent_photo.driver');

            $driver = match ($configured) {
                'gd' => GdDriver::class,
                'imagick' => ImagickDriver::class,
                default => throw new PhotoDefinitionException(sprintf(
                    'Config key [eloquent_photo.driver] must be "gd" or "imagick", %s given.',
                    is_string($configured) ? '"'.$configured.'"' : get_debug_type($configured)
                )),
            };

            // v4 replaced v3's ImageManager::gd() helpers with usingDriver().
            return ImageManager::usingDriver($driver);
        });

        // Same caching caveat as the ImageManager binding above: the root directory
        // is captured once, at first resolution.
        $this->app->singleton(PathGenerator::class, static function (Application $app): PathGenerator {
            $directory = $app->make(ConfigRepository::class)->get('eloquent_photo.directory');

            return new PathGenerator(is_string($directory) ? $directory : '');
        });

        $this->app->singleton(PhotoProcessor::class, static function (Application $app): PhotoProcessor {
            return new PhotoProcessor(
                $app->make(ImageManager::class),
                $app->make(FilesystemFactory::class),
            );
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
