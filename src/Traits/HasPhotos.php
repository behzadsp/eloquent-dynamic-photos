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
            Storage::disk(config('eloquent_photo.disk'))->delete($this->$photoField);

            return true;
        }

        return false;
    }

    public function updatePhoto($photo, string $photoField): Model
    {
        $this->deletePhotoFile($photoField);

        if (!Storage::disk(config('eloquent_photo.disk'))->exists(config('eloquent_photo.root_directory') . '/' . $this->getDirName())) {
            Storage::disk(config('eloquent_photo.disk'))->makeDirectory(
                config('eloquent_photo.root_directory') . '/' . $this->getDirName(),
            );
        }

        $photoPath = $this->getPhotoDirectoryPath();

        Image::make($photo)
            ->encode(config('eloquent_photo.format'), config('eloquent_photo.quality'))
            ->save($this->getPhotoFullPath($photoPath), config('eloquent_photo.quality'));

        $this->$photoField = $photoPath;

        $this->saveOrFail();

        return $this;
    }

    public function getPhotoFullPath(string $photoPath)
    {
        return Storage::disk(config('eloquent_photo.disk'))->path($photoPath);
    }

    public function getPhotoDirectoryPath()
    {
        return config('eloquent_photo.root_directory') .
            '/' .
            $this->getDirName() .
            '/' .
            str($this->slug)
                ->limit(config('eloquent_photo.slug_limit'))
                ->toString() .
            '_' .
            Carbon::now()->format(config('eloquent_photo.timestamp_format')) .
            '.' . config('eloquent_photo.format');
    }

    public function getDirName(): string
    {
        return str(class_basename($this))
            ->plural()
            ->lower()
            ->toString();
    }

    public function __get($key)
    {
        if (str_starts_with($key, 'photo') && str_ends_with($key, 'url')) {
            $photoField = str_replace('url', '', $key);
            return $this->$photoField
                ? Storage::disk(config('eloquent_photo.disk'))->url($this->$photoField)
                : null;
        }

        return parent::__get($key);
    }
}