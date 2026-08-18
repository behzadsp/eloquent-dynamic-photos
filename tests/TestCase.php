<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Tests;

use Behzadsp\EloquentDynamicPhotos\Providers\EloquentDynamicPhotosServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EloquentDynamicPhotosServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // testbench defaults the "testing" connection to sqlite :memory:.
        $app['config']->set('database.default', 'testing');
        $app['config']->set('eloquent_photo.disk', 'photos');
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->nullable();
            $table->string('title')->nullable();
            $table->string('photo')->nullable();
            $table->string('banner')->nullable();
            // Present only to prove the _url accessor does not hijack real
            // columns (spec defect #7).
            $table->string('website')->nullable();
            $table->string('website_url')->nullable();
            // Present only to pin defect #6 with a field literally named
            // "..._url": see PhotoAccessorTest's "strips only the _url
            // suffix" test.
            $table->string('image_url')->nullable();
            $table->timestamps();
        });

        Schema::create('soft_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->nullable();
            $table->string('photo')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
}
