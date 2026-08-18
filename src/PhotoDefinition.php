<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos;

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;
use Behzadsp\EloquentDynamicPhotos\Support\ValidatesPathTokens;

/**
 * Per-field photo configuration. Any argument left null falls back to the
 * matching key in config/eloquent_photo.php.
 */
final readonly class PhotoDefinition
{
    use ValidatesPathTokens;

    public string $disk;

    /**
     * Raw directory override. Null means "derive it", which PathGenerator does
     * by joining the configured root with the model's own directory.
     */
    public ?string $directory;

    public string $nameAttribute;

    public int $nameLimit;

    public string $format;

    public int $quality;

    public string $timestampFormat;

    /** @var array<string, Conversion> */
    public array $conversions;

    public int $maxFileSize;

    public ?int $maxWidth;

    public ?int $maxHeight;

    /** @var list<string> */
    public array $allowedMimeTypes;

    public bool $autoOrient;

    public bool $queueConversions;

    /**
     * @param  array<string, Conversion>  $conversions
     * @param  list<string>|null  $allowedMimeTypes
     */
    public function __construct(
        ?string $disk = null,
        ?string $directory = null,
        ?string $nameAttribute = null,
        ?int $nameLimit = null,
        ?string $format = null,
        ?int $quality = null,
        ?string $timestampFormat = null,
        array $conversions = [],
        ?int $maxFileSize = null,
        ?int $maxWidth = null,
        ?int $maxHeight = null,
        ?array $allowedMimeTypes = null,
        ?bool $autoOrient = null,
        ?bool $queueConversions = null,
    ) {
        // ponytail: reads config() directly rather than taking an injected defaults
        // array. It keeps all fourteen fallbacks in one place and leaves every
        // consumer free of null checks, and every test here already needs a booted
        // app for Storage::fake(). Inject a defaults array if that ever stops being
        // true.
        if ($directory !== null) {
            self::assertSafeDirectory($directory);
        }

        $this->disk = $disk ?? self::configString('disk');
        $this->directory = $directory;
        $this->nameAttribute = $nameAttribute ?? self::configString('name_attribute');
        $this->nameLimit = $nameLimit ?? self::configInt('name_limit');
        $this->format = $format ?? self::configString('format');
        self::assertPathToken($this->format, 'Format', '/^[A-Za-z0-9]+$/');
        $this->quality = $quality ?? self::configInt('quality');
        $this->timestampFormat = $timestampFormat ?? self::configString('timestamp_format');
        $this->maxFileSize = $maxFileSize ?? self::configInt('max_file_size');
        $this->autoOrient = $autoOrient ?? self::configBool('auto_orient');
        $this->queueConversions = $queueConversions ?? self::configBool('queue_conversions');

        // maxWidth and maxHeight are legitimately nullable — null disables that
        // axis — so they cannot use ?? against config and still allow an explicit
        // null. Passing null here means "use the configured value"; set the config
        // key to null to disable the bound globally.
        $this->maxWidth = $maxWidth ?? self::configNullableInt('max_width');
        $this->maxHeight = $maxHeight ?? self::configNullableInt('max_height');

        $this->allowedMimeTypes = $allowedMimeTypes ?? self::configStringList('allowed_mime_types');

        foreach ($conversions as $name => $conversion) {
            // Conversion names are developer-chosen labels that reach
            // PathGenerator::conversionPath() unsanitized, so they get the same
            // strict allowlist as format strings. An integer key (an accidental
            // numeric-string key, which PHP casts to int on its own) is rejected
            // outright rather than stringified and pattern-checked.
            if (! is_string($name)) {
                throw new PhotoDefinitionException(sprintf(
                    'Conversion name [%s] must be a string, %s given.',
                    (string) $name,
                    get_debug_type($name)
                ));
            }

            self::assertPathToken($name, 'Conversion name', '/^[A-Za-z0-9_-]+$/');

            if (! $conversion instanceof Conversion) {
                throw new PhotoDefinitionException(sprintf(
                    'Conversion [%s] must be a Conversion instance, %s given.',
                    $name,
                    get_debug_type($conversion)
                ));
            }
        }

        $this->conversions = $conversions;
    }

    public function conversion(string $name): Conversion
    {
        return $this->conversions[$name]
            ?? throw new PhotoDefinitionException(sprintf(
                'No conversion named [%s]. Declared: %s.',
                $name,
                $this->conversions === [] ? 'none' : implode(', ', array_keys($this->conversions))
            ));
    }

    /**
     * The directory override is trusted developer configuration, not request
     * data — but it commonly interpolates a model attribute to build a
     * per-tenant path (e.g. "tenants/{$tenant->slug}"), which puts the same
     * class of user-controllable value here that the name attribute's
     * sanitizer exists to neutralise. Unlike the name attribute, this string
     * is never slugified — a slash is a legitimate separator here — so a
     * traversal segment is rejected outright at declaration time rather than
     * silently rewritten at upload time.
     */
    private static function assertSafeDirectory(string $directory): void
    {
        // A null byte defeats the exact "." / ".." segment comparison below
        // ("..\0" !== ".."), so it is rejected up front rather than folded
        // into the segment loop.
        if (str_contains($directory, '\\') || str_contains($directory, "\0")) {
            throw new PhotoDefinitionException(self::unsafeDirectoryError($directory));
        }

        foreach (explode('/', $directory) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new PhotoDefinitionException(self::unsafeDirectoryError($directory));
            }
        }
    }

    private static function unsafeDirectoryError(string $directory): string
    {
        return sprintf(
            'Directory [%s] must not contain ".", "..", a null byte or backslash segments.',
            $directory
        );
    }

    private static function config(string $key): mixed
    {
        return config('eloquent_photo.'.$key);
    }

    // The helpers below exist to narrow config()'s mixed return to the
    // property's real type, so phpstan can verify the assignment above instead
    // of trusting a cast. Config misconfiguration (wrong type in
    // eloquent_photo.php) throws the same exception as any other definition
    // error rather than silently coercing bad data.
    //
    // configInt/configNullableInt/configBool also accept env()-shaped strings:
    // Laravel's env() leaves numeric strings as strings and types only
    // true/false/null, so a published config reading env('PHOTO_QUALITY', 75)
    // yields "80", not 80. That is a standard pattern, not a misconfiguration,
    // so unambiguous string forms are coerced; anything else still throws.

    private static function configString(string $key): string
    {
        $value = self::config($key);

        if (! is_string($value)) {
            throw new PhotoDefinitionException(self::configTypeError($key, 'a string', $value));
        }

        return $value;
    }

    private static function configInt(string $key): int
    {
        $value = self::config($key);
        $int = self::toIntOrNull($value);

        return $int ?? throw new PhotoDefinitionException(self::configTypeError($key, 'an integer', $value));
    }

    private static function configNullableInt(string $key): ?int
    {
        $value = self::config($key);

        if ($value === null) {
            return null;
        }

        $int = self::toIntOrNull($value);

        return $int ?? throw new PhotoDefinitionException(self::configTypeError($key, 'an integer or null', $value));
    }

    /**
     * Accepts a genuine int, or a numeric string whose int round-trip is
     * identical (so "80" passes but "80.5", "abc", " 80", and "080" do not).
     */
    private static function toIntOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }

    private static function configBool(string $key): bool
    {
        $value = self::config($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new PhotoDefinitionException(self::configTypeError($key, 'a boolean', $value));
    }

    /** @return list<string> */
    private static function configStringList(string $key): array
    {
        $value = self::config($key);

        if (! is_array($value) || ! array_is_list($value)) {
            throw new PhotoDefinitionException(self::configTypeError($key, 'a list of strings', $value));
        }

        $strings = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new PhotoDefinitionException(self::configTypeError($key, 'a list of strings', $item));
            }

            $strings[] = $item;
        }

        return $strings;
    }

    private static function configTypeError(string $key, string $expected, mixed $value): string
    {
        return sprintf(
            'Config key [eloquent_photo.%s] must be %s, %s given.',
            $key,
            $expected,
            get_debug_type($value)
        );
    }
}
