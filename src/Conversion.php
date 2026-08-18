<?php

declare(strict_types=1);

namespace Behzadsp\EloquentDynamicPhotos;

use Behzadsp\EloquentDynamicPhotos\Exceptions\PhotoDefinitionException;

final readonly class Conversion
{
    /**
     * @param  int|null  $width  Target width. Null means "derive from height".
     * @param  int|null  $height  Target height. Null means "derive from width".
     * @param  string|null  $format  Overrides the field's format. Null inherits it.
     * @param  int|null  $quality  Overrides the field's quality. Null inherits it.
     */
    public function __construct(
        public ?int $width = null,
        public ?int $height = null,
        public Fit $fit = Fit::Contain,
        public ?string $format = null,
        public ?int $quality = null,
    ) {
        if ($width === null && $height === null) {
            throw new PhotoDefinitionException(
                'A conversion needs a width, a height, or both.'
            );
        }

        // Intervention's cover() and resize() both take two required dimensions.
        if ($fit !== Fit::Contain && ($width === null || $height === null)) {
            throw new PhotoDefinitionException(sprintf(
                'Fit::%s needs both a width and a height.',
                $fit->name
            ));
        }
    }
}
