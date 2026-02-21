<?php

// src/Component/ImageVariantGd.php

namespace Kmergen\MediaBundle\Component;

use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ManipulatorInterface;

class ImageVariantGd
{
  private static ?Imagine $imagine = null;

  public static function resize(
    string $imgRef,
    ?int $width,
    ?int $height,
    string $mode = 'inbound'
  ): ImageInterface {
    if (self::$imagine === null) {
      self::$imagine = new Imagine();
    }

    $image = self::$imagine->open($imgRef);

    // Imagine requires actual > 0 integers for Box.
    // We calculate the missing dimension based on original aspect ratio!
    $size = $image->getSize();
    $origWidth = $size->getWidth();
    $origHeight = $size->getHeight();

    if ($width === null && $height === null) {
      $w = $origWidth;
      $h = $origHeight;
    } elseif ($width === null || $width === 0) {
      $w = (int) round($origWidth * ($height / $origHeight));
      $h = $height;
    } elseif ($height === null || $height === 0) {
      $w = $width;
      $h = (int) round($origHeight * ($width / $origWidth));
    } else {
      $w = $width;
      $h = $height;
    }

    $box = new Box($w, $h);

    if ($mode === 'outbound') {
      // Resize to fill the box and crop the overflow
      $image = $image->thumbnail($box, ManipulatorInterface::THUMBNAIL_OUTBOUND);
    } else {
      // Resize to fit inside the box without cropping
      $image = $image->thumbnail($box);
    }

    return $image;
  }

  public static function crop(string $imgRef, ?int $width, ?int $height): ImageInterface
  {
    if (self::$imagine === null) {
      self::$imagine = new Imagine();
    }

    $image = self::$imagine->open($imgRef);
    $size = $image->getSize();

    // If one dimension is missing, default to making it a perfect square
    $w = $width ?? $height ?? $size->getWidth();
    $h = $height ?? $width ?? $size->getHeight();

    $box = new Box($w, $h);

    // Use thumbnail function to resize and crop
    return $image->thumbnail($box, ManipulatorInterface::THUMBNAIL_OUTBOUND);
  }

  public static function compositeBlur(
    string $imgRef,
    ?int $width,
    ?int $height
  ): ImageInterface { // Fixed return type to ImageInterface!
    $image = self::resize($imgRef, $width, $height);

    // Apply blur, then return the image itself (effects() might return EffectsInterface otherwise)
    $image->effects()->blur(5);

    return $image;
  }
}
