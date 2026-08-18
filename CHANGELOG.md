# Changelog

All notable changes to `eloquent-dynamic-photos` will be documented in this file, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

## 2.0.0

### Added

- `photoDefinitions(): array<string, PhotoDefinition>` — a per-field declaration API
  (`PhotoDefinition`, `Conversion`, `Fit`) replacing v1's seven per-model override methods.
  Two photo columns on the same model can now have different disks, directories, formats, and
  conversions.
- Named conversions (thumbnails, etc.), declared per field and generated from the stored
  primary on `updatePhoto()`. `regeneratePhotoConversions($field)` backfills them for rows
  stored before a conversion existed; it's idempotent and safe to retry.
- Automatic file cleanup on model delete, via an Eloquent `deleted` hook. Respects soft
  deletes (files survive a soft delete, are removed on `forceDelete()`), waits for the
  enclosing transaction to commit before touching disk, and can be turned off globally with
  `delete_files_on_model_delete`.
- Optional queued conversion generation (`queue_conversions`, `queue.connection`, `queue.name`)
  via a new `ProcessConversionsJob`. The primary is always written synchronously; only
  conversions can be deferred.
- Upload validation in `PhotoProcessor`: a MIME allowlist checked against the decoded image
  (not a client-supplied header), `max_file_size`, `max_width`/`max_height` (downscale, never
  reject, never upscale), and a `max_pixels` pre-decode pixel-budget guard that accounts for
  frame count in GIF and WebP sources.
- `auto_orient` config option: bakes EXIF rotation into the pixels before encoding (needs
  `ext-exif`; a no-op without it).
- `deletePhoto($field)`, new in v2: deletes the file(s) **and** clears and saves the column.
  (`deletePhotoFiles($field)` — the renamed v1 `deletePhotoFile()` — deletes files only.)
- `StorageFailedException`, raised when the configured disk rejects a write (`Storage::put()`
  returning `false`, which Laravel's default filesystem config does on failure instead of
  throwing) — for both the primary and every conversion.
- A real test suite: pest + orchestra/testbench, real encoded image fixtures, `Storage::fake()`
  for filesystem assertions. `composer test`, `composer test:coverage`, `composer lint`, and
  `composer analyse` scripts. A GitHub Actions CI matrix (PHP 8.3–8.5 × Laravel 11–13).
  larastan at level max, zero suppressions in `src/`.
- The config file moved to `config/eloquent_photo.php` and is published under the
  `eloquent-photo-config` tag.

### Changed

- Raised the PHP floor to `^8.3`, narrowed Laravel support to `^11.0 || ^12.0 || ^13.0`, and
  bumped `intervention/image` from `^2.7` (EOL) to `^4.2`.
- Default `quality` raised from `50` to `75` — 50 was visibly lossy as a default. New uploads
  are larger and look better; existing stored files are unaffected.
- Config key `root_directory` renamed to `directory`; `slug_limit` renamed to `name_limit`.
- `photoFullPath()`, `photoPath()`, and `photoUrl()` now take a **field name**, not a raw path
  — v1's `getPhotoFullPath()` took the path itself.
- Conversion files now live at `{directory}/{model-dir}/conversions/{primary-basename}/{conversion}.{format}`
  — one directory per primary, holding every conversion derived from it — so a primary's whole
  conversion set can be deleted as a single directory instead of needing every declared name.
- The image driver (`gd`/`imagick`) is now selected at runtime via the `driver` config key and
  resolved through Intervention's v4 `ImageManager::usingDriver()`, instead of a hard
  dependency on `ext-gd`.
- `updatePhoto()` no longer opens a transaction around the save (v1 used `saveOrFail()`): the
  file write already happens outside any transaction, so the transaction bought nothing and
  only widened the window a DB lock was held during I/O.

### Fixed

Each of the following was a defect in v1, each now has a named regression test:

- `getEloquentPhotoTimestampFormat()` called `$this->eloquentTimestampFormat()`, a method that
  did not exist — fatal for any user who followed the README. The whole override-method API is
  gone; there's nothing left to call.
- `Str::limit()`'s default `...` terminator leaked into every filename generated over the
  configured length limit.
- The name attribute was interpolated into the storage path unsanitized, so a user-controlled
  value containing `/` or `..` segments could write outside the intended directory. All
  filename construction now routes through `Str::slug()`.
- `updatePhoto()` deleted the old file *before* writing the new one, so any failure afterward
  left the database column pointing at a file that no longer existed. v2 writes the new file,
  saves, generates conversions, and only then deletes the old file.
- The freshly encoded image was discarded and encoded a second time, doubling CPU and peak
  memory on every upload. v2 encodes once.
- The `_url` accessor used `str_replace('_url', '', $key)`, which strips every occurrence of
  the substring, not just the suffix — a field named `image_url`, accessed as `image_url_url`,
  resolved to field `image` (both `_url` occurrences removed) instead of `image_url`. v2 uses
  `substr($key, 0, -4)`, which strips only the trailing occurrence.
- The `_url` accessor fired for any attribute pair ending in `_url`, so a model with a real
  `website` and `website_url` column silently returned `Storage::url($website)` for
  `website_url`. It's now gated on fields declared in `photoDefinitions()`; everything else
  falls through to the normal attribute resolution.
- A dead `exists()`/`makeDirectory()` code path ran before every write, even though
  `Storage::put()` already creates intermediate directories — and the `exists()` check was
  unreliable on S3 besides. Removed.
- `str()` requires Laravel 9, while `composer.json` claimed support for `^7.0|^8.0`. The
  support matrix is now honest: `^11.0 || ^12.0 || ^13.0`.
- `getEloquentPhotoQuality()` and `getEloquentPhotoSlugLimit()` were typed to return `string`
  but actually returned config integers, which then fed parameters expecting `int`. Replaced by
  typed, readonly `int` properties on `PhotoDefinition`.
- `getPhotoFullPath()` called `Storage::path()`, which on a remote disk (S3, etc.) doesn't
  throw — it silently returns a locally-prefixed key that isn't a real filesystem path at all,
  useless for any local file operation attempted against it. `photoFullPath()` now inspects the
  resolved disk adapter and throws `UnsupportedDiskOperation`, naming the disk, for anything
  that isn't a local Flysystem adapter.
- `deletePhotoFile()` returned `true` whenever the column was non-empty, regardless of whether
  the underlying delete actually succeeded. `deletePhotoFiles()` now returns the filesystem's
  own boolean.
- `composer test` was documented in the README with no tests, no dev dependencies, and no
  `scripts` block behind it. The package now ships a real suite and the scripts that run it.
- `intervention/image` v2 was EOL, there was no Laravel 12 support, and `php >=7.4` blocked
  native typed properties throughout. See "Changed" above.
- The trait injected unprefixed `getFileName()`, `getDirName()`, and `getAttribute()` into
  every consuming model — a near-certain collision with application code. Internals are now
  prefixed `eloquentPhoto*`, and `getFileName()`/`getDirName()` moved into `PathGenerator`.
- There was no model-delete hook, so every deleted row orphaned its files permanently. A
  `deleted` event listener now cleans them up by default (see README for the bulk-delete and
  concurrency caveats this can't cover).

### Removed

- The seven `eloquentPhotoX()` override methods: `eloquentPhotoDisk()`,
  `eloquentPhotoFormat()`, `eloquentPhotoQuality()`, `eloquentPhotoRootDirectory()`,
  `eloquentPhotoNameAttribute()`, `eloquentPhotoSlugLimit()`, `eloquentPhotoTimestampFormat()`.
  Replaced by `photoDefinitions()` (see [UPGRADE.md](UPGRADE.md)).
- `getFileName()` and `getDirName()` as public/protected methods on the consuming model — moved
  into `PathGenerator`, internal to the package.
- The direct `nesbot/carbon` dependency. `illuminate/support` still pulls it in transitively,
  and `PathGenerator` uses Laravel's own `Illuminate\Support\Carbon` wrapper.
