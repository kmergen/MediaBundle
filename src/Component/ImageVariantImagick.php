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
