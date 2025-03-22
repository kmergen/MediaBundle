<?php

// src/Service/ImageService.php

namespace Kmergen\MediaBundle\Service;

use Imagick;
use Imagine\Image\ImageInterface;
use Kmergen\MediaBundle\Component\ImageVariantGd;
use Kmergen\MediaBundle\Component\ImageVariantImagick;
use Symfony\Component\Filesystem\Filesystem;

readonly class ImageService
{

  public function __construct(private Filesystem $filesystem)
  {
  }

  public function thumb(string $imgRef, string $variant, bool $force = false): string
  {
    list($action, $width, $height, $quality) = explode(',', $variant);
    $variantDir = $this->generateVariantDirectory($imgRef, $action, $width, $height, $quality);
    $variantPath = $variantDir . DIRECTORY_SEPARATOR . basename($imgRef);

    if (!$force && $this->filesystem->exists($variantPath)) {
      return $variantPath;
    }

    // Determine whether to use GD or Imagick
    $image = extension_loaded('imagick')
      ? $this->processWithImagick($action, $imgRef, (int)$width, (int)$height)
      : $this->processWithGd($action, $imgRef, (int)$width, (int)$height);

    // Save the image
    return $this->saveImage($image, $variantPath, (int)$quality);
  }

  private function processWithImagick(string $action, string $imgRef, int $width, int $height): Imagick
  {
    return match ($action) {
      'resize' => ImageVariantImagick::resize($imgRef, $width, $height),
      'crop' => ImageVariantImagick::crop($imgRef, $width, $height),
      'compositeBlur' => ImageVariantImagick::compositeBlur($imgRef, $width, $height),
      default => throw new \InvalidArgumentException("Unknown variant action: $action"),
    };
  }

  private function processWithGd(string $action, string $imgRef, int $width, int $height): ImageInterface
  {
    return match ($action) {
      'resize' => ImageVariantGd::resize($imgRef, $width, $height),
      'crop' => ImageVariantGd::crop($imgRef, $width, $height),
      'compositeBlur' => ImageVariantGd::compositeBlur($imgRef, $width, $height),
      default => throw new \InvalidArgumentException("Unknown variant action: $action"),
    };
  }

  private function saveImage(object $image, string $path, int $quality): string
  {
    $this->filesystem->mkdir(dirname($path));

    if ($image instanceof ImageInterface) {
      $image->save($path, ['quality' => $quality * 10]);
    } elseif ($image instanceof Imagick) {
      $image->setImageCompressionQuality($quality);
      $image->writeImage($path);
    } else {
      throw new \InvalidArgumentException("Unsupported image type.");
    }

    return $path;
  }

  private function generateVariantDirectory(
    string $imgRef,
    string $action,
    string $width,
    string $height,
    string $quality
  ): string {
    $pathInfo = pathinfo($imgRef);
    return sprintf(
      '%s/%s_%s_%s_%s',
      $pathInfo['dirname'],
      $action,
      $width,
      $height,
      $quality
    );
  }
}
