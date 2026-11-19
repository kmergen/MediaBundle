<?php

// src/Component/ImageVariantImagick.php

namespace Kmergen\MediaBundle\Component;

use Imagick;

class ImageVariantImagick
{
  public static function resize(string $imgRef, ?int $width, ?int $height): Imagick
  {
    // Fallback null to 0 for Imagick compatibility
    $w = $width ?? 0;
    $h = $height ?? 0;

    // Replicating your original logic: If height is missing/0, create a bounding box of WxW
    $bestfit = $h === 0;
    if ($bestfit && $w > 0) {
      $h = $w;
    }

    $imagick = new Imagick($imgRef);
    $originalWidth = $imagick->getImageWidth();
    $originalHeight = $imagick->getImageHeight();

    // Never upscale: an original that is already smaller than the requested size is kept as it is.
    if ($bestfit || $w === 0) {
      // Best fit, or only a height: the aspect ratio is kept, so the original fits when it is not
      // larger in any requested dimension.
      if (($w === 0 || $originalWidth <= $w) && ($h === 0 || $originalHeight <= $h)) {
        return $imagick;
      }
    } else {
      // An exact box: shrink it, keeping its proportions, until it fits inside the original.
      [$w, $h] = VariantSize::limitToOriginal($w, $h, $originalWidth, $originalHeight);
    }

    $imagick->resizeImage($w, $h, Imagick::FILTER_LANCZOS, 1, $bestfit);
    return $imagick;
  }

  public static function crop(string $imgRef, ?int $width, ?int $height): Imagick
  {
    $imagick = new Imagick($imgRef);

    // If one dimension is missing, default to making it a perfect square
    // If BOTH are missing, fallback to original dimensions
    $w = $width ?? $height ?? $imagick->getImageWidth();
    $h = $height ?? $width ?? $imagick->getImageHeight();

    // Never upscale: keep the requested proportions, but at most the size the original allows.
    [$w, $h] = VariantSize::limitToOriginal($w, $h, $imagick->getImageWidth(), $imagick->getImageHeight());

    $imagick->cropThumbnailImage($w, $h);
    return $imagick;
  }

  public static function compositeBlur(string $imgRef, ?int $width, ?int $height): Imagick
  {
    $imagick = self::resize($imgRef, $width, $height);
    $imagick->blurImage(5, 3);
    return $imagick;
  }
}
