<?php

declare(strict_types=1);

return [
    // Filesystem disk used to store photos.
    'disk' => 'public',

    // Root directory. The model's own directory is appended, e.g. "images/posts".
    'directory' => 'images',

    // Model attribute the filename is derived from.
    'name_attribute' => 'slug',

    // Maximum length of the name portion of the filename.
    'name_limit' => 240,

    // Stored format. Any format intervention/image can encode.
    'format' => 'webp',

    // Encoding quality, 1-100.
    'quality' => 75,

    // Format of the timestamp appended to filenames. "U" is a Unix timestamp.
    'timestamp_format' => 'U',

    // Image driver: "gd" or "imagick".
    'driver' => 'gd',

    // Reject uploads larger than this, in kilobytes.
    'max_file_size' => 10240,

    // Bounding box for stored photos. Larger images are scaled down, not rejected.
    // Set either to null to disable that axis.
    'max_width' => 2560,
    'max_height' => 2560,

    // Reject images whose pixel count exceeds this before decoding them. Byte
    // size is not a proxy for memory: a 200 KB image can be 8000x8000 and cost
    // several hundred MB to decode, because the full bitmap must exist before it
    // can be scaled down. Rough arithmetic is ~7 bytes per pixel including
    // scaling overhead, so raise this and your memory_limit together.
    'max_pixels' => 40_000_000,

    // Allowlist, checked against the decoded image rather than any client-supplied
    // header. SVG is excluded on purpose: served from a public disk it is a stored
    // XSS vector, and it is not a raster format the encoder can normalise.
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ],

    // Bake EXIF orientation into the pixels before encoding. Needs ext-exif.
    'auto_orient' => true,

    // Delete photo files when a model is deleted. Soft deletes are ignored;
    // files are removed on force-delete.
    'delete_files_on_model_delete' => true,

    // Generate conversions on a queue instead of during the request.
    'queue_conversions' => false,

    // Connection and queue for conversion jobs. null uses the app defaults.
    'queue' => [
        'connection' => null,
        'name' => null,
    ],
];
