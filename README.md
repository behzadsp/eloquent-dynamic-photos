# Laravel Eloquent Photos

[![Latest Version on Packagist](https://img.shields.io/packagist/v/behzadsp/eloquent-dynamic-photos.svg?style=flat-square)](https://packagist.org/packages/behzadsp/eloquent-dynamic-photos)
[![Total Downloads](https://img.shields.io/packagist/dt/behzadsp/eloquent-dynamic-photos.svg?style=flat-square)](https://packagist.org/packages/behzadsp/eloquent-dynamic-photos)
[![Tests](https://github.com/behzadsp/eloquent-dynamic-photos/actions/workflows/tests.yml/badge.svg)](https://github.com/behzadsp/eloquent-dynamic-photos/actions/workflows/tests.yml)

A Laravel Eloquent trait for storing a photo per model column: validation, format conversion,
named size conversions (thumbnails, etc.), and automatic cleanup — without a media-library
data model. One file per declared field; no extra database tables.

Upgrading from v1? See [UPGRADE.md](UPGRADE.md) — the model-facing API changed completely.

## Requirements

| | |
|---|---|
| PHP | `^8.3` |
| Laravel | `^11.0 \|\| ^12.0 \|\| ^13.0` |
| intervention/image | `^4.2` |

No image extension is a hard requirement — the driver (`gd` or `imagick`) is chosen at
runtime via config. `composer.json` suggests:

- **`ext-gd`** — required if you use the default `gd` driver.
- **`ext-imagick`** — required if you set `driver` to `imagick`.
- **`ext-exif`** — required for `auto_orient` to actually do anything. Without it, EXIF
  orientation cannot be read, `orient()` silently becomes a no-op, and photos uploaded
  sideways from a phone are stored sideways.

## Installation

```bash
composer require behzadsp/eloquent-dynamic-photos
```

Publish the config file:

```bash
php artisan vendor:publish --tag="eloquent-photo-config"
```

This publishes `config/eloquent_photo.php`, shown in full below.

## Quick start

Add the `HasPhotos` trait to a model and declare which columns hold photos by overriding
`photoDefinitions()`. Keys are column names; values are per-field configuration. Anything you
don't pass falls back to the config file.

```php
use Behzadsp\EloquentDynamicPhotos\Conversion;
use Behzadsp\EloquentDynamicPhotos\Fit;
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
                conversions: [
                    'thumb' => new Conversion(width: 96, height: 96, fit: Fit::Cover),
                ],
            ),
            'cover' => new PhotoDefinition(), // every setting comes from config
        ];
    }
}
```

```php
$user = User::find(1);

$user->updatePhoto($request->file('avatar'), 'avatar');   // validates, stores, saves, converts
$user->photoUrl('avatar');                                 // public URL of the primary
$user->photoUrl('avatar', 'thumb');                        // public URL of the "thumb" conversion
$user->avatar_url;                                          // same as photoUrl('avatar')

$user->deletePhoto('avatar');       // deletes files, sets the column to null, saves
$user->deletePhotoFiles('avatar');  // deletes files, leaves the column as-is
```

`updatePhoto()` accepts an `UploadedFile`, an `SplFileInfo`, an absolute path string, a
binary image string, or a stream resource. It does **not** accept a remote URL — this
package will not fetch a user-supplied URL server-side.

## Configuration

Every top-level key here is the default a `PhotoDefinition` argument falls back to when you
don't pass that argument explicitly.

```php
<?php

return [
    'disk' => 'public',
    'directory' => 'images',
    'name_attribute' => 'slug',
    'name_limit' => 240,
    'format' => 'webp',
    'quality' => 75,
    'timestamp_format' => 'U',
    'driver' => 'gd',                 // gd | imagick
    'max_file_size' => 10240,         // KB
    'max_width' => 2560,
    'max_height' => 2560,
    'max_pixels' => 40_000_000,
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ],
    'auto_orient' => true,
    'delete_files_on_model_delete' => true,
    'queue_conversions' => false,
    'queue' => [
        'connection' => null,
        'name' => null,
    ],
];
```

| Key | Meaning |
|---|---|
| `disk` | Filesystem disk (from `config/filesystems.php`) files are stored on. |
| `directory` | Root directory. Each model gets its own subdirectory under it — see below. |
| `name_attribute` | Model attribute the filename is derived from (slugified). |
| `name_limit` | Maximum length of the slugified name portion of the filename. |
| `format` | Stored format for the primary file — any format `intervention/image` can encode. |
| `quality` | Encoding quality, passed straight to the encoder. |
| `timestamp_format` | `date()`-style format for the timestamp segment of the filename. `U` is a Unix timestamp. |
| `driver` | `gd` or `imagick`. Resolved once per container lifetime — see the caveat below. |
| `max_file_size` | Reject uploads whose compressed size (KB) exceeds this. |
| `max_width` / `max_height` | Bounding box for the stored primary. Larger images are scaled down, never rejected, never upscaled. Set either to `null` to disable that axis. |
| `max_pixels` | Reject an image whose *decoded* pixel budget (width × height × frame count) exceeds this, checked before decoding. See "The memory guard is incomplete" below. |
| `allowed_mime_types` | Allowlist checked against the decoded image, not any client-supplied header. SVG is deliberately absent — see below. |
| `auto_orient` | Bake EXIF rotation into the pixels before encoding. Needs `ext-exif`; a no-op without it. |
| `delete_files_on_model_delete` | Whether the delete hook runs at all. Must be a real boolean or a booleanish string — see below. |
| `queue_conversions` | Generate named conversions on a queue instead of inline during `updatePhoto()`. |
| `queue.connection` / `queue.name` | Queue connection/name for conversion jobs. `null` uses the app's defaults. |

**The `driver` and `directory` config values are read once and cached for the life of the
container** (they back a `singleton()` binding). A long-running worker (Octane, a queue
worker) that changes either per-request or per-tenant must call
`app()->forgetInstance(\Intervention\Image\ImageManager::class)` (or `PathGenerator::class`)
itself to pick up the change.

**SVG is excluded from the default allowlist on purpose.** It is not a raster format the
encoder can normalise, and an SVG served back from a public disk is a stored XSS vector.
Do not add it unless you understand and accept that risk.

**`delete_files_on_model_delete` must be a real boolean or a booleanish string**
(`true`/`false`, `1`/`0`, `yes`/`no`, `on`/`off`) — anything else, including `null`, throws
`PhotoDefinitionException` rather than silently disabling cleanup. The realistic way to hit
this is `env('PHOTO_DELETE_FILES_ON_MODEL_DELETE')` with no default, which is `null` when the
variable isn't set. This is deliberate: the package fails loudly on bad config everywhere else,
and this check runs inside the `deleted` event listener, so it throws *after* the row is
already deleted (or soft-deleted) — the delete itself isn't rolled back, only the file cleanup
never runs. It also throws for soft deletes, not just hard ones, because this check runs before
the soft-delete check that would otherwise skip file handling entirely. Give `env()` a default
if you use it for this key.

### Storage layout

```
{directory}/{model-dir}/{name}_{random}_{timestamp}.{format}                        primary
{directory}/{model-dir}/conversions/{primary-basename}/{conversion}.{format}        conversion
```

`{model-dir}` is the plural, lowercased class basename (`User` → `users`). A `directory`
passed to `PhotoDefinition` replaces the **entire** prefix, both segments.

Conversion paths are **derived from the stored primary path** by string arithmetic — v2
stores no separate conversion columns and needs no migration. This has a consequence; see
"A conversion path is derived, not verified" below.

## Public API

| Method | Behaviour |
|---|---|
| `updatePhoto(mixed $photo, string $field): static` | Validate, encode, store the primary, `save()`, generate/queue conversions, then delete the old file(s) — deferred until the enclosing transaction commits, if there is one, so a rollback doesn't leave the old file gone while the column is reverted. |
| `deletePhoto(string $field): bool` | Clear the column, `save()`, then delete the file(s) — deferred until the enclosing transaction commits, if there is one, so a rollback doesn't leave the file gone while the column is restored. Returns whether there was a photo recorded to delete, not whether the disk deletion has actually happened yet: `false` means the column was already empty or unusable, `true` means the column was cleared and cleanup was scheduled (or run immediately, best-effort, outside a transaction). |
| `deletePhotoFiles(string $field): bool` | Delete the file(s), leave the column as it is. The boolean reflects only the primary file's own delete outcome — `false` also covers "there was nothing recorded to delete" (an empty or tampered column), not just a failed disk operation. The conversions directory is deleted alongside it, but that outcome isn't reflected in the return value at all. |
| `photoUrl(string $field, ?string $conversion = null): ?string` | Public URL, or `null` if the column is empty. |
| `photoPath(string $field, ?string $conversion = null): ?string` | Disk-relative path, or `null` if the column is empty. |
| `photoFullPath(string $field, ?string $conversion = null): ?string` | Absolute local filesystem path. Throws `UnsupportedDiskOperation` if the field's disk isn't a local filesystem (S3, etc. — there is no local path to give you). |
| `regeneratePhotoConversions(string $field): void` | Re-derive every declared conversion from the currently stored primary. Idempotent. |
| `{field}_url` | Sugar for `photoUrl($field)`, for every field declared in `photoDefinitions()`. |

Passing a conversion name that isn't declared on that field to `photoUrl()`, `photoPath()`,
or `photoFullPath()` throws `PhotoDefinitionException` rather than silently returning a URL
to a file that was never generated. Calling any of these methods (or `updatePhoto()`) with a
field that isn't a key in `photoDefinitions()` throws the same exception.

**"Deferred until commit" doesn't mean "never" in your tests.** `updatePhoto()`'s old-file
cleanup, `deletePhoto()`'s cleanup, and the model-delete hook (below) all defer their file
deletion to the enclosing transaction's commit when there is one, so a rollback can't leave a
restored/reverted row pointing at a file that's already gone. Under Laravel's testing
transaction wrapper (`RefreshDatabase`/`DatabaseTransactions`), that callback runs immediately
instead of waiting for a commit that never really happens — so a test that calls
`updatePhoto()` or `deletePhoto()` directly (without wrapping it in its own `DB::transaction()`)
and then asserts the old file is gone still passes.

All exceptions this package throws extend `Behzadsp\EloquentDynamicPhotos\Exceptions\EloquentPhotoException`
(itself a `RuntimeException`), so you can catch one type if you want. The others are
`InvalidPhotoException` (bad/oversized/disallowed source), `UnsupportedDiskOperation`
(`photoFullPath()` on a non-local disk), and `StorageFailedException` — raised when the disk
itself rejects a write. `Storage::put()` returns `false` rather than throwing unless a disk
opts into exceptions, which Laravel's default filesystem config does not, so without this
check a rejected write would otherwise be silent: the column gets saved pointing at a path
with no file behind it, and no error at all. This applies to both the primary write and every
conversion write.

### The `{field}_url` accessor is not `$appends`-compatible

`$model->avatar_url` works out of the box for any field declared in `photoDefinitions()`.
But **do not add it to `$appends`**:

```php
protected $appends = ['avatar_url']; // throws BadMethodCallException
```

This throws because of how Laravel resolves appended attributes. `toArray()`/`toJson()`
read appended keys through `get{Studly}Attribute()` — for `avatar_url` that's
`getAvatarUrlAttribute()` — and never go through this trait's `getAttribute()` override,
which is the only place the `_url` suffix is understood. Since no such method exists, Laravel's
`Model::__call()` forwards the call to the query builder, which doesn't have it either, and
you get a `BadMethodCallException`.

Direct property access (`$model->avatar_url`) works fine because that goes through
`getAttribute()`. Only the `$appends`/serialization path is broken.

If you need the URL in `toArray()`/JSON, define your own attribute accessor and append
*that* instead:

```php
use Illuminate\Database\Eloquent\Casts\Attribute;

class User extends Model
{
    use HasPhotos;

    protected $appends = ['avatar_thumb_url'];

    protected function avatarThumbUrl(): Attribute
    {
        return Attribute::get(fn () => $this->photoUrl('avatar', 'thumb'));
    }

    protected function photoDefinitions(): array
    {
        // ...
    }
}
```

This works because Laravel resolves `Attribute`-typed accessor methods (`avatarThumbUrl()`
for the `avatar_thumb_url` key) directly, independent of `getAttribute()`. Name your
accessor something other than the bare field name (`avatarUrl`, `photoUrl`, etc.) — those
collide with this trait's own `photoUrl($field, $conversion)` method.

## Behaviours you should know about

These are not bugs — they're consequences of how this package works that aren't obvious
from the method signatures above. Each was found and verified while building v2.

**1. Whether an animated upload stays animated depends on both the driver and the target
format.** Verified against the installed `intervention/image` 4.2.1 source and by encoding
real animated GIF/WebP fixtures through each combination:

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

**2. `max_width`, `max_height` and `max_pixels` can only be disabled globally**, by setting the
config key to `null`. Passing `null` for one of these to `PhotoDefinition` means "use the
configured value" — there is no way to disable a bound for one field while keeping it for
others. If you need that, give the field its own disk/directory and set the bound globally to
`null` for that separate configuration, or fork the config per environment.

**3. The pixel budget only understands two multi-frame containers.** `max_pixels` budgets
total decoded pixels (`width × height × frame count`) before decoding, because compressed byte
size does not bound decode memory — a small, highly-compressible image can still be an enormous
bitmap once decoded. Frame count is read from the raw bytes, and only two containers are
understood: GIF (via Graphic Control Extension markers) and WebP (via `ANMF` chunks). Any other
multi-frame container measures as a single frame, so its budget is understated by however many
frames it actually holds.

Two things keep that from being exploitable with the shipped config. `allowed_mime_types` is
checked **twice**: once against the container sniffed from the raw header before the decoder is
ever called, and again against the decoded image's own media type. The pre-decode check is the
one that matters for memory, and it is why a format the allowlist does not name costs nothing
at all — the shipped list is JPEG, PNG, WebP, GIF and AVIF, of which only GIF and WebP are
multi-frame, and both are counted correctly. (AVIF can carry an image sequence, but ImageMagick
decodes only its primary item: measured at 35 MB for a 100-frame 1920×1080 AVIF.)

The residual risk is therefore specifically **a multi-frame container you add to
`allowed_mime_types` yourself**, since the frame counter will read it as one frame. Nothing else
in the stack will save you there. `max_file_size` bounds compressed bytes, not decode cost. Nor
does PHP's `memory_limit` reliably: it provides some backstop under `gd` (an allocation large
enough to exceed a finite `memory_limit` fails the request), but it does **not** bound `imagick`
at all — ImageMagick allocates its pixel buffers natively, outside PHP's memory manager.
Measured in this package's own test environment with `memory_limit` effectively unlimited: a
1.4 MB, 100-page LZW TIFF fed through the `imagick` driver allocated roughly 3 GB before the
post-decode check rejected it. With the pre-decode check in front of it, the same file costs
0 MB — but only because `image/tiff` is not on the list. Put it on the list and the 3 GB is
yours.

So if you run the `imagick` driver and extend `allowed_mime_types` beyond the shipped set, also
set a hard ceiling on the decoder itself. It is the only structurally complete defence, because
it caps the decoder directly instead of predicting its cost from bytes:

```php
Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
```

**4. A conversion path is derived, not verified.** `photoPath($field, 'thumb')` and
`photoUrl($field, 'thumb')` compute the path by string arithmetic from the stored primary —
that's why v2 needs no migration for conversions — so they return a path whether or not the
file actually exists there. A conversion failure during `updatePhoto()` does throw (it isn't
swallowed) — including a disk that silently rejects the write: `Storage::put()` returns
`false` rather than throwing on Laravel's default filesystem config, so this package checks
that return value itself and raises `StorageFailedException` rather than letting a missing
file pass unnoticed. Reaching this state therefore requires the caller to have caught and discarded that
exception, or a file to have been removed out-of-band afterwards — but if you need certainty,
check the disk. `regeneratePhotoConversions()` is idempotent, so re-running it is always safe.

**5. `updatePhoto()` and `deletePhoto()` call `save()`.** Any other unsaved changes on the
model are persisted along with the photo change.

**6. `queue_conversions` needs a working queue-failure pipeline, and it widens an existing
race.** Conversion failures deliberately propagate rather than being swallowed — a conversion
path is derived (point 4 above), so silently swallowing a failure would leave `photoUrl()`
pointing at a file that was never written. With `queue_conversions` off, that exception
reaches the code that called `updatePhoto()`. With it **on**, the exception surfaces inside a
queue **worker**, not the uploader — so enabling `queue_conversions` without a working
`failed_jobs` table (or Horizon, or a `queue.failed` listener) means a permanently failed
conversion produces no signal anywhere, and `photoUrl($field, 'thumb')` will keep handing out
URLs to a file that doesn't exist. Treat a failure pipeline as a prerequisite for
`queue_conversions`, not an optional nicety.

Re-uploading a field while its previous conversion job is still queued is handled: the job
compares the row's current raw column value against the path it was dispatched for, and
no-ops quietly if a newer upload has already replaced it. But a residual hazard remains under
true concurrency — if a queued job is mid-write when a second upload's cleanup deletes that
primary's whole conversion directory, partially-written files can be dropped, or left behind
as bytes nothing will ever collect, because that directory is no longer anyone's "old path" by
the time the job finishes. The same hazard exists in the synchronous path (two simultaneous
`updatePhoto()` calls on the same row and field), but queueing widens the window from
milliseconds to however long your queue is backed up.

A related, deliberately-parked case: if a row is soft-deleted before a queued conversion job
for it runs, the job silently no-ops. `ProcessConversionsJob::handle()` looks the row up with
a plain `find()`, which — like any query on a `SoftDeletes` model — excludes trashed rows by
default, so the job treats a soft-deleted row exactly like a genuinely deleted one and returns
without generating anything. The files aren't touched (soft delete keeps them, as above), but
the conversion is simply never made, and after a later `restore()`, `photoUrl($field, 'thumb')`
will keep handing out a URL to a file that was never written. This was left unfixed on purpose
— using `withTrashed()` in the job would do conversion work for a row that may never come back.
If you restore a row that had conversions queued while it was trashed, call
`regeneratePhotoConversions($field)` afterwards to fill them in.

**7. Bulk deletes bypass the cleanup hook entirely.** File cleanup on delete runs off
Eloquent's `deleted` model event. `Model::where(...)->delete()`, `truncate()`, and
`deleteQuietly()` all skip Eloquent model events by design, so the `deleted` listener never
fires and their files are silently orphaned. This is inherent to any event-based hook, not a
defect in this package — but it's a foreseeable source of orphaned files for an app doing
bulk cleanup. If the files matter, iterate the models and call `delete()` (or `forceDelete()`)
on each row instead of a bulk query.

## Testing

```bash
composer test            # pest
composer test:coverage   # pest --coverage
composer lint             # pint
composer analyse          # phpstan (larastan), level max
```

## License

The MIT License (MIT). Please see the [License File](LICENSE) for more information.
