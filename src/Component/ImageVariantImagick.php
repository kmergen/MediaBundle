<?php

// src/Component/ImageVariantImagick.php

namespace Kmergen\MediaBundle\Component;

use Imagick;

class ImageVariantImagick
{
  public static function resize(string $imgRef, int $width, int $height, int $quality): Imagick
  {
    $imagick = new Imagick($imgRef);
    $imagick->resizeImage($width, $height, Imagick::FILTER_LANCZOS, 1);
    $imagick->setImageCompressionQuality($quality);
    return $imagick;
  }

  public static function crop(string $imgRef, int $width, int $height, int $quality): Imagick
  {
    $imagick = new Imagick($imgRef);
    $imagick->cropThumbnailImage($width, $height);
    $imagick->setImageCompressionQuality($quality);
    return $imagick;
  }

  public static function compositeBlur(string $imgRef, int $width, int $height, int $quality): Imagick
  {
    $imagick = self::resize($imgRef, $width, $height, $quality);
    $imagick->blurImage(5, 3);
    $imagick->setImageCompressionQuality($quality);
    return $imagick;
  }
}
