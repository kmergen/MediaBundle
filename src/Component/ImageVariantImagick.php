<?php

// src/Component/ImageVariantImagick.php

namespace Kmergen\MediaBundle\Component;

use Imagick;

class ImageVariantImagick
{
  public static function resize(string $imgRef, int $width, int $height): Imagick
  {
    $imagick = new Imagick($imgRef);
    $imagick->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
    return $imagick;
  }

  public static function crop(string $imgRef, int $width, int $height): Imagick
  {
    $imagick = new Imagick($imgRef);
    $imagick->cropThumbnailImage($width, $height);
    return $imagick;
  }

  public static function compositeBlur(string $imgRef, int $width, int $height): Imagick
  {
    $imagick = self::resize($imgRef, $width, $height);
    $imagick->blurImage(5, 3);
    return $imagick;
  }
}
