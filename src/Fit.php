<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos;

/**
 * How a conversion fits its target box.
 *
 * Contain maps to Intervention's scaleDown, not contain(): contain() pads to the
 * exact box with a background colour, which is not what a thumbnail wants.
 */
enum Fit: string
{
    /** Fit inside the box, preserving aspect ratio. Never upscales, never pads. */
    case Contain = 'contain';

    /** Fill the box, preserving aspect ratio, cropping the overflow. */
    case Cover = 'cover';

    /** Force the exact box, ignoring aspect ratio. */
    case Stretch = 'stretch';
}
