<?php

namespace Behzadsp\EloquentDynamicPhotos\Providers;

use Illuminate\Support\ServiceProvider;

class EloquentDynamicPhotosServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/eloquent_photo.php' => config_path('eloquent_photo.php'),
        ], 'config');

        // Load default configuration
        $this->mergeConfigFrom(__DIR__.'/../config/eloquent_photo.php', 'eloquent_photo');
    }

    public function register()
    {
        //
    }
}
