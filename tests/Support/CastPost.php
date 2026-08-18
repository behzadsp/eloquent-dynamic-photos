<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Tests\Support;

use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Fit;
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\Traits\HasPhotos;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Model;

/**
 * Backed by the posts table since no new schema is needed — this exists only
 * to prove ProcessConversionsJob compares the raw column, not the cast one:
 * AsStringable makes getAttribute('photo') return a Stringable, which would
 * never equal the raw $dispatchedPath string.
 */
final class CastPost extends Model
{
    use HasPhotos;

    protected $table = 'posts';

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'photo' => AsStringable::class,
    ];

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
        ];
    }
}
