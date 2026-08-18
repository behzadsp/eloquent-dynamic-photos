<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SoftPost extends Model
{
    use SoftDeletes;

    protected $table = 'soft_posts';

    protected $guarded = [];
}
