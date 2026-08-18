<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos\Tests\Support;

use Illuminate\Database\Eloquent\Model;

final class Post extends Model
{
    protected $table = 'posts';

    protected $guarded = [];
}
