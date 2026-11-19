<?php

// src/Component/VariantSize.php

namespace Kmergen\MediaBundle\Component;

/**
 * Size rules for the image variant generator (ImageVariantImagick).
 */
final class VariantSize
{
  /**
   * Shrinks a requested target box so that it never exceeds the original image.
   *
   * The box keeps its own aspect ratio, so a crop still has the requested proportions; it is only
   * scaled down until it fits inside the original. Boxes that already fit are returned unchanged.
   *
   * @return array{int, int}
   */
  public static function limitToOriginal(int $width, int $height, int $originalWidth, int $originalHeight): array
  {
    if ($width <= 0 || $height <= 0 || $originalWidth <= 0 || $originalHeight <= 0) {
      return [$width, $height];
    }

    $factor = min(1, $originalWidth / $width, $originalHeight / $height);
    if ($factor >= 1) {
      return [$width, $height];
    }

    return [max(1, (int) round($width * $factor)), max(1, (int) round($height * $factor))];
  }
}
