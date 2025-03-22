<?php

// src/Component/ImageVariantGd.php

namespace Kmergen\MediaBundle\Component;

use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ManipulatorInterface;

class ImageVariantGd
{
  private static $imagine;

  public static function resize(
    string $imgRef,
    int $width,
    int $height,
    string $mode = 'inbound'
  ): ImageInterface {
    if (self::$imagine === null) {
      self::$imagine = new Imagine();
    }

    $image = self::$imagine->open($imgRef);
    $box = new Box($width, $height);

    if ($mode === 'outbound') {
      // Resize to fill the box and crop the overflow
      $image = $image->thumbnail($box, ManipulatorInterface::THUMBNAIL_OUTBOUND);
    } else {
      // Resize to fit inside the box without cropping
      $image = $image->thumbnail($box);
    }

    return $image;
  }

  public static function crop(string $imgRef, int $width, int $height): ImageInterface
  {
    if (self::$imagine === null) {
      self::$imagine = new Imagine();
    }

    $image = self::$imagine->open($imgRef);
    $box = new Box($width, $height);

    // Use thumbnail function to resize and crop
    return $image->thumbnail($box, ManipulatorInterface::THUMBNAIL_OUTBOUND);
  }

  public static function compositeBlur(
    string $imgRef,
    int $width,
    int $height,
  ): \Imagine\Effects\EffectsInterface {
    $image = self::resize($imgRef, $width, $height);
    return $image->effects()->blur(5);
  }
}
