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

  public function __construct(private Filesystem $filesystem) {}

  /**
   * Generates a thumbnail for the given image reference based on the specified variant.
   *
   * The variant string is expected to be in the format: "action,width,height,quality".
   * For example: "resize,200,200,80".
   *
   * @param string $imgRef The path to the original image file.
   * @param string $variant The variant string specifying the action, width, height, and quality.
   * @param bool $force Whether to force regeneration of the thumbnail even if it already exists.
   *
   * @return string The path to the generated thumbnail.
   *
   * @throws \InvalidArgumentException If the variant action is unknown.
   * @throws \RuntimeException If the image processing fails.
   */
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

  /**
   * Processes the image using the Imagick library.
   *
   * @param string $action The action to perform (e.g., 'resize', 'crop', 'compositeBlur').
   * @param string $imgRef The path to the original image file.
   * @param int $width The target width for the image.
   * @param int $height The target height for the image.
   *
   * @return Imagick The processed Imagick object.
   *
   * @throws \InvalidArgumentException If the variant action is unknown.
   */
  private function processWithImagick(string $action, string $imgRef, int $width, int $height): Imagick
  {
    return match ($action) {
      'resize' => ImageVariantImagick::resize($imgRef, $width, $height),
      'crop' => ImageVariantImagick::crop($imgRef, $width, $height),
      'compositeBlur' => ImageVariantImagick::compositeBlur($imgRef, $width, $height),
      default => throw new \InvalidArgumentException("Unknown variant action: $action"),
    };
  }

  /**
   * Processes the image using the GD library.
   *
   * @param string $action The action to perform (e.g., 'resize', 'crop', 'compositeBlur').
   * @param string $imgRef The path to the original image file.
   * @param int $width The target width for the image.
   * @param int $height The target height for the image.
   *
   * @return ImageInterface The processed image object.
   *
   * @throws \InvalidArgumentException If the variant action is unknown.
   */
  private function processWithGd(string $action, string $imgRef, int $width, int $height): ImageInterface
  {
    return match ($action) {
      'resize' => ImageVariantGd::resize($imgRef, $width, $height),
      'crop' => ImageVariantGd::crop($imgRef, $width, $height),
      'compositeBlur' => ImageVariantGd::compositeBlur($imgRef, $width, $height),
      default => throw new \InvalidArgumentException("Unknown variant action: $action"),
    };
  }

  /**
   * Saves the processed image to the specified path with the given quality.
   *
   * This method supports both `ImageInterface` (e.g., from GD) and `Imagick` objects.
   * It ensures the directory for the image exists before saving and applies the specified quality.
   *
   * @param object $image The image object to save. Must be an instance of `ImageInterface` or `Imagick`.
   * @param string $path The path where the image should be saved.
   * @param int $quality The quality of the saved image (0–100 for GD, 1–100 for Imagick).
   *
   * @return string The path to the saved image.
   *
   * @throws \InvalidArgumentException If the image type is unsupported.
   * @throws \RuntimeException If the directory creation or image saving fails.
   */
  private function saveImage(object $image, string $path, int $quality): string
  {
    $this->filesystem->mkdir(dirname($path));

    if ($image instanceof ImageInterface) {
      $image->save($path, ['quality' => $quality]);
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
      '%s/%s_%sx%s_q%s',
      $pathInfo['dirname'],
      $action,
      $width,
      $height,
      $quality
    );
  }
}
