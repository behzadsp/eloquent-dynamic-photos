<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Providers;

use Illuminate\Support\ServiceProvider;

class EloquentDynamicPhotosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/eloquent_photo.php', 'eloquent_photo');
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
