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
    'quality' => 50, // Quality for encoding the photos
    'format' => 'webp', // Format of the stored photos
    'slug_limit' => 240, // Name limit to save in database
    'timestamp_format' => 'U', // U represents Unix timestamp
];

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



You can now use the methods provided by the trait in your models:

```php
$model = YourModel::first();

// delete photo
$model->deletePhotoFile('photo_field');

// update photo
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

The MIT License (MIT). Please see License File for more information.

The badges at the top of the README are optional and you'll need to replace the URLs with the correct ones for your package. They provide a quick overview of the package's current version and total downloads.
