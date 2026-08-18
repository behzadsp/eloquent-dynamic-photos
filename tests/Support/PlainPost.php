<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately does NOT use HasPhotos. Backed by the posts table since no
 * new schema is needed for a model that only exists to prove a caller
 * without the trait is rejected.
 */
final class PlainPost extends Model
{
    protected $table = 'posts';

    protected $guarded = [];
}
