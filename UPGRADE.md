# Upgrading from v1 to v2

v2 is a rewrite of the model-facing API. Existing stored files keep resolving — only new
uploads use the v2 path scheme, and there is no migration to run. `regeneratePhotoConversions()`
backfills named conversions for rows that were uploaded before v2 (or before a conversion was
declared at all).

## What changed

| v1 | v2 |
|---|---|
| Seven `eloquentPhotoX()` override methods (`eloquentPhotoDisk()`, `eloquentPhotoFormat()`, `eloquentPhotoQuality()`, `eloquentPhotoRootDirectory()`, `eloquentPhotoNameAttribute()`, `eloquentPhotoSlugLimit()`, `eloquentPhotoTimestampFormat()`) | One `photoDefinitions()` method returning an `array<string, PhotoDefinition>` |
| `deletePhotoFile($field)` | `deletePhotoFiles($field)` — same behaviour, renamed for honesty (v1 returned `true` whenever the column was non-empty, regardless of whether the delete actually succeeded; v2 returns the filesystem's own boolean). Or use `deletePhoto($field)` if you also want the column cleared and saved. |
| `getPhotoFullPath($path)` | `photoFullPath($field)` — **takes a field name now, not a path** |
| `getPhotoDirectoryPath()` | `photoPath($field)` — the v1 name was wrong; despite the name, it returned a *file* path, not a directory |
| `getFileName()`, `getDirName()` | Internal now, moved into `PathGenerator`. No longer injected into your model. |
| config `root_directory` | config `directory` |
| config `slug_limit` | config `name_limit` |
| config `quality: 50` | config `quality: 75` — 50 is visibly lossy as a default |
| `$model->photo_url` | Unchanged in shape, but now only works for fields declared in `photoDefinitions()` |

## The three changes most likely to break silently

These won't necessarily throw or fail a test — they'll just produce the wrong value if you
carry the v1 call site over unchanged.

1. **`photoFullPath()` takes a field, not a path.** v1's `getPhotoFullPath($path)` took the
   value you already had (e.g. `$model->photo`) and resolved it to an absolute path. v2's
   `photoFullPath($field)` takes the *column name* (`'photo'`) and resolves the stored value
   itself. Passing the old path string as `$field` will throw `PhotoDefinitionException`
   ("not declared as a photo") rather than silently doing the wrong thing — but the call site
   needs to change either way.

2. **`getPhotoDirectoryPath()` is not `photoPath()`'s ancestor in behaviour, only in
   intent.** Despite its name, v1's `getPhotoDirectoryPath()` returned a *file* path, not a
   directory. `photoPath($field, ?$conversion)` is its honest replacement: it returns the
   primary's disk-relative path (or a conversion's, if you pass one), or `null` if the column
   is empty.

3. **Default `quality` moved from 50 to 75.** Every *newly stored* file will be larger and
   look better than before. If you need byte-for-byte parity with v1's output, set
   `quality: 50` explicitly, either in `config/eloquent_photo.php` or per-field on
   `PhotoDefinition`. Existing files are untouched either way — this only affects new writes.

## Two more things to know before you upgrade

**Pre-v2 rows can now throw where they used to silently return a wrong value.** v1's defect #3
let an unsanitized name attribute reach the storage path, so a v1-era row can legitimately have
a `..` segment (or, separately, a null byte) sitting in its stored photo column. v2's
`photoPath()`, `photoUrl()`, `photoFullPath()`, and `$model->photo_url` all now throw
`InvalidPhotoException` the moment they see that in a stored value, rather than handing back a
path that could escape the disk root. That's the better failure — and it's recoverable, since
calling `updatePhoto()` overwrites the column with a freshly generated, safe path regardless of
what was there before — but it means a v1-era row carrying a tampered value that used to read
back a (wrong) value will now throw on read instead, until it's re-uploaded.

**`deletePhotoFiles()` deletes immediately — it does not wait for a transaction to commit.**
`deletePhoto()` and the model-delete cleanup hook both defer their file deletion until the
enclosing transaction commits, so a rollback can't leave a restored row pointing at a file
that's already gone. `deletePhotoFiles()` is different by design: its whole purpose is to
remove files while leaving the column untouched, so there's no column change for a rollback to
undo, and deferring the delete would only leave files sitting around for no benefit. Calling
`deletePhotoFiles()` inside a transaction you might roll back does not protect the files —
they're gone immediately, even if the transaction later aborts.

## Before / after

**v1:**

```php
use Behzadsp\EloquentDynamicPhotos\Traits\HasPhotos;

class User extends Model
{
    use HasPhotos;

    protected function eloquentPhotoDisk()
    {
        return 's3';
    }

    protected function eloquentPhotoNameAttribute()
    {
        return 'username';
    }

    protected function eloquentPhotoFormat()
    {
        return 'webp';
    }

    protected function eloquentPhotoQuality()
    {
        return '80';
    }

    protected function eloquentPhotoRootDirectory()
    {
        return 'avatars';
    }

    protected function eloquentPhotoSlugLimit()
    {
        return '100';
    }

    protected function eloquentPhotoTimestampFormat()
    {
        return 'U';
    }
}

// usage
$model->deletePhotoFile('avatar');
$model->updatePhoto($photo, 'avatar');
$model->getPhotoFullPath($model->avatar);
$model->getPhotoDirectoryPath();
$model->avatar_url;
```

**v2:**

```php
use Behzadsp\EloquentDynamicPhotos\PhotoDefinition;
use Behzadsp\EloquentDynamicPhotos\Traits\HasPhotos;

class User extends Model
{
    use HasPhotos;

    protected function photoDefinitions(): array
    {
        return [
            'avatar' => new PhotoDefinition(
                disk: 's3',
                nameAttribute: 'username',
                format: 'webp',
                quality: 80,
                directory: 'avatars',
                nameLimit: 100,
                timestampFormat: 'U',
            ),
        ];
    }
}

// usage
$model->deletePhotoFiles('avatar');
$model->updatePhoto($photo, 'avatar');
$model->photoFullPath('avatar');
$model->photoPath('avatar');
$model->avatar_url;
```

If you have several photo columns per model, each now gets its own entry in the
`photoDefinitions()` array instead of sharing one set of per-model overrides — this is the
main reason v2 exists: v1 had no way to give two fields on the same model different formats,
disks, or directories.

## Named conversions are new

v1 had no concept of a derived thumbnail/size. If you want one, declare it per field:

```php
'avatar' => new PhotoDefinition(
    conversions: [
        'thumb' => new \Behzadsp\EloquentDynamicPhotos\Conversion(
            width: 96,
            height: 96,
            fit: \Behzadsp\EloquentDynamicPhotos\Fit::Cover,
        ),
    ],
),
```

For rows that already have a stored primary from before you declared the conversion (or from
before the v2 upgrade at all), call `regeneratePhotoConversions($field)` to backfill it. It's
idempotent, so it's safe to run in a queued job, a console command, or a one-off tinker loop
over existing rows.

See [README.md](README.md) for the full v2 API and the behaviours worth knowing about before
you rely on any of this in production.
