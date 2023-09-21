<?php

namespace Behzadsp\EloquentDynamicPhotos\Traits;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

trait HasPhotos
{
    public function deletePhotoFile(string $photoField): bool
    {
        if ($this->$photoField) {
            Storage::disk($this->getEloquentPhotoDisk())->delete(
                $this->$photoField,
            );

            return true;
        }

        return false;
    }

    public function updatePhoto($photo, string $photoField): Model
    {
        $this->deletePhotoFile($photoField);

        if (
            !Storage::disk($this->getEloquentPhotoDisk())->exists(
                $this->getEloquentPhotoRootDirectory() .
                    '/' .
                    $this->getDirName(),
            )
        ) {
            Storage::disk($this->getEloquentPhotoDisk())->makeDirectory(
                $this->getEloquentPhotoRootDirectory() .
                    '/' .
                    $this->getDirName(),
            );
        }

        $photoPath = $this->getPhotoDirectoryPath();

        Image::make($photo)
            ->encode(
                $this->getEloquentPhotoFormat(),
                $this->getEloquentPhotoQuality()
            )
            ->save(
                $this->getPhotoFullPath($photoPath),
                $this->getEloquentPhotoQuality()
            );

        $this->$photoField = $photoPath;

        $this->saveOrFail();

        return $this;
    }

    public function getPhotoFullPath(string $photoPath)
    {
        return Storage::disk($this->getEloquentPhotoDisk())->path($photoPath);
    }

    public function getPhotoDirectoryPath()
    {
        $nameAttribute = $this->getEloquentPhotoNameAttribute();

        return $this->getEloquentPhotoRootDirectory() .
            '/' .
            $this->getDirName() .
            '/' .
            str($this->$nameAttribute)
            ->limit($this->getEloquentPhotoSlugLimit())
            ->toString() .
            '_' .
            Carbon::now()->format($this->getEloquentPhotoTimestampFormat()) .
            '.' .
            $this->getEloquentPhotoFormat();
    }

    public function getDirName(): string
    {
        return str(class_basename($this))
            ->plural()
            ->lower()
            ->toString();
    }

    public function getAttribute($key)
    {
        if (str_ends_with($key, '_url')) {
            $photoField = str_replace('_url', '', $key);

            if (array_key_exists($photoField, $this->attributes)) {
                if ($this->attributes[$photoField] === null) {
                    return null;
                }
                
                return Storage::disk($this->getEloquentPhotoDisk())->url(
                    $this->attributes[$photoField],
                );
            }
        }

        return parent::getAttribute($key);
    }

    protected function getEloquentPhotoDisk(): string
    {
        if (method_exists($this, 'eloquentPhotoDisk')) {
            return $this->eloquentPhotoDisk();
        }

        return config('eloquent_photo.disk');
    }

    protected function getEloquentPhotoRootDirectory(): string
    {
        if (method_exists($this, 'eloquentPhotoRootDirectory')) {
            return $this->eloquentPhotoRootDirectory();
        }

        return config('eloquent_photo.root_directory');
    }

    protected function getEloquentPhotoFormat(): string
    {
        if (method_exists($this, 'eloquentPhotoFormat')) {
            return $this->eloquentPhotoFormat();
        }

        return config('eloquent_photo.format');
    }

    protected function getEloquentPhotoQuality(): string
    {
        if (method_exists($this, 'eloquentPhotoQuality')) {
            return $this->eloquentPhotoQuality();
        }

        return config('eloquent_photo.quality');
    }

    protected function getEloquentPhotoNameAttribute(): string
    {
        if (method_exists($this, 'eloquentPhotoNameAttribute')) {
            return $this->eloquentPhotoNameAttribute();
        }

        return config('eloquent_photo.name_attribute');
    }

    protected function getEloquentPhotoSlugLimit(): string
    {
        if (method_exists($this, 'eloquentPhotoSlugLimit')) {
            return $this->eloquentPhotoSlugLimit();
        }

        return config('eloquent_photo.slug_limit');
    }

    protected function getEloquentPhotoTimestampFormat(): string
    {
        if (method_exists($this, 'eloquentPhotoTimestampFormat')) {
            return $this->eloquentTimestampFormat();
        }

        return config('eloquent_photo.timestamp_format');
    }
}
