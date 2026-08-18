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
Every other container — including a static image in any format, or an animated format not
listed here (a multi-page TIFF, for example) — counts as one frame.

`max_file_size` still applies to every upload, but compressed bytes are not a proxy for decode
cost, so it does not close this gap on its own. Nor does PHP's `memory_limit`: it bounds the
`gd` driver, but it does **not** bound `imagick` — ImageMagick allocates memory natively,
outside PHP's memory manager, so a decode that exceeds available memory under `imagick` will
not be stopped by `memory_limit`. This is measured, not theoretical: a 544 KB, 100-page LZW
TIFF costs roughly 986 MB to decode under `imagick`, entirely outside what this guard budgets.

If you run the `imagick` driver — especially with `allowed_mime_types` extended beyond this
package's default, which excludes TIFF and every other multi-page or multi-frame format besides
GIF and WebP — set a hard ceiling on the decoder itself. It is the only structurally complete
defence available, because it caps the decoder directly instead of predicting its cost from
bytes:

```php
Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
```

Whether an animated source is *stored* as an animation depends on both the driver and the
target `format`, verified against the installed `intervention/image` 4.2.1 source and by
encoding real animated GIF/WebP fixtures through each combination:

- `gd` + `gif`: **preserved**. `Gd\Encoders\GifEncoder::encode()` calls `encodeAnimated()`
  whenever `$image->isAnimated()`.
- `gd` + `webp` (and every other `gd` target format): **flattened to one frame**. Only
  `GifEncoder` checks `isAnimated()`; every other `gd` encoder reads
  `$image->core()->native()`, which `gd`'s `Core` always resolves to the first frame.
- `imagick` + `gif`: **preserved**. `Imagick\Core::native()` returns the underlying `Imagick`
  object holding every frame, and `GifEncoder` writes it with `getImagesBlob()` unconditionally.
- `imagick` + `webp`: **preserved** when the source `isAnimated()` — `WebpEncoder` explicitly
  skips its single-layer merge step in that case.

Not tested: other `imagick` target formats (PNG, JPEG, etc.) also read the full multi-frame
object and call `getImagesBlob()`, so they may write more than one image into the output file —
but whether that produces a viewable animation depends on the format, and was not verified.

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
