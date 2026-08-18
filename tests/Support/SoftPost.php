<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Tests\Support;

use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\Traits\HasPhotos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The soft_posts table is defined in TestCase::defineDatabaseMigrations(),
 * not a conventional database/migrations/*.php file, so larastan's migration
 * scanner can't see it; this @property tag is what PHPStan's own class-level
 * phpDoc support uses instead.
 *
 * @property string|null $photo
 */
final class SoftPost extends Model
{
    use HasPhotos;
    use SoftDeletes;

    protected $table = 'soft_posts';

    protected $guarded = [];

    /**
     * @return array<string, PhotoDefinition>
     */
    protected function photoDefinitions(): array
    {
        return ['photo' => new PhotoDefinition];
    }
}
