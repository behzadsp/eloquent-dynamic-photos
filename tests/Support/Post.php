<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Tests\Support;

use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Fit;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\Traits\HasPhotos;
use Illuminate\Database\Eloquent\Model;

/**
 * The columns below live in TestCase::defineDatabaseMigrations(), not a
 * conventional database/migrations/*.php file, so larastan's migration
 * scanner can't see them; these @property tags are what PHPStan's own
 * class-level phpDoc support uses instead.
 *
 * @property int $id
 * @property string|null $slug
 * @property string|null $title
 * @property string|null $photo
 * @property string|null $banner
 * @property string|null $website
 * @property string|null $website_url
 * @property-read string|null $photo_url
 * @property-read string|null $banner_url
 */
final class Post extends Model
{
    use HasPhotos;

    protected $table = 'posts';

    protected $guarded = [];

    /**
     * @return array<string, PhotoDefinition>
     */
    protected function photoDefinitions(): array
    {
        return [
            'photo' => new PhotoDefinition(
                conversions: [
                    'thumb' => new Conversion(width: 20, height: 20, fit: Fit::Cover),
                ],
            ),
            'banner' => new PhotoDefinition(format: 'jpeg', directory: 'banners'),
        ];
    }
}
