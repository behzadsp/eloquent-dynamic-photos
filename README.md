# Laravel Eloquent Photos

[![Latest Version on Packagist](https://img.shields.io/packagist/v/behzadsp/eloquent-dynamic-photos.svg?style=flat-square)](https://packagist.org/packages/behzadsp/eloquent-dynamic-photos)
[![Total Downloads](https://img.shields.io/packagist/dt/behzadsp/eloquent-dynamic-photos.svg?style=flat-square)](https://packagist.org/packages/behzadsp/eloquent-dynamic-photos)

This is a Laravel Eloquent trait that provides an easy and dynamic way to manage photos in your Eloquent models.

## Installation

You can install the package via composer:

```bash
  composer require behzadsp/eloquent-dynamic-photos
```

You can publish the config file with:

```bash
php artisan vendor:publish --provider="Behzadsp\EloquentDynamicPhotos\Providers\EloquentDynamicPhotosServiceProvider"
```

This is the contents of the global configurations for uploading images.

```php
<?php

return [
    'disk' => 'public', // Disk to use for storing photos
    'root_directory' => 'images', // Root directory for photos
    'name_attribute' => 'slug', // Model attribute used for file name
    'quality' => 50, // Quality for encoding the photos
    'format' => 'webp', // Format of the stored photos
    'slug_limit' => 240, // Name limit to save in database
    'timestamp_format' => 'U', // U represents Unix timestamp
];

```

## Animated images and the pixel-budget guard

Uploads are checked against `max_pixels` (default 40,000,000) before they are decoded, because
compressed byte size does not bound decode memory: a small, highly-compressible image can still
be an enormous bitmap once decoded. For an animated source, the budget is `width * height *
frame count`, not just `width * height` — `getimagesize()` only ever reports the first frame, so
a low-resolution animation with many frames can otherwise slip through while still costing
hundreds of MB to decode.

Frame count is read from the file's bytes, not by decoding it, and only two containers are
understood: GIF (via its Graphic Control Extension markers) and WebP (via its `ANMF` chunks).
Any other format — including a static image in any format, or an animated format not listed
here — counts as one frame and relies on `max_file_size` and PHP's `memory_limit` as backstops.

Whether an animated source is *stored* as an animation also depends on your configured
`driver`:

- `gd` (the default) always flattens animation to a single frame on encode — GD's WebP and most
  other encoders cannot write more than one frame regardless of the source.
- `imagick` preserves animation on encode where the target format supports it (WebP, GIF).

If you run the `imagick` driver, consider also bounding the decoder itself in your application's
bootstrap, as the only structurally complete defence against a decode-memory spike — this guard
predicts cost from bytes, which can never be exhaustive, while a resource limit caps the decoder
directly:

```php
Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
```

## Usage

After installing the package, simply use the `HasPhotos` trait in your Eloquent models:

```php
use Behzadsp\EloquentDynamicPhotos\Traits\HasPhotos;

class YourModel extends Model
{
    use HasPhotos;

    // ...
}
```

Of course, you can override certain config in individual model by declaring the corresponding method. Like below:

```php
class User extends Model
{
    use HasPhotos;

    protected function eloquentPhotoDisk()
    {
        return 'user-avatar';
    }

    protected function eloquentPhotoFormat()
    {
        return 'png';
    }
    
    protected function eloquentPhotoRootDirectory()
    {
        return 'images';
    }
    
    protected function eloquentPhotoQuality()
    {
        return '50';
    }
    
    protected function eloquentPhotoNameAttribute()
    {
        return 'slug';
    }
    
    protected function eloquentPhotoSlugLimit()
    {
        return '240';
    }
    
    protected function eloquentPhotoTimestampFormat()
    {
        return 'U';
    }
}
```

You can now use the methods provided by the trait in your models:

```php
$model = YourModel::first();

// delete photo file only and not database column.
$model->deletePhotoFile('photo_field');

// update photo file and save it to database column.
$model->updatePhoto($photo, 'photo_field');

// get full photo path
$model->getPhotoFullPath('photo_path');

// get photo directory path
$model->getPhotoDirectoryPath();

// get photo URL
$model->photo_field_url;
```

## Testing

```bash
  composer test
```

## License

The MIT License (MIT). Please see [License File](https://github.com/behzadsp/eloquent-dynamic-photos/blob/main/LICENSE) for more information.
