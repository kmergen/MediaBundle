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
   * Generates a thumbnail for the given image reference.
   *
   * @param string $imgRef   The path to the original image file.
   * @param string $action   The action to perform (e.g., 'resize', 'crop').
   * @param int|null $width  The target width (null for auto-calculation).
   * @param int|null $height The target height (null for auto-calculation).
   * @param int $quality     The image quality (1-100). Default is 80.
   * @param bool $force      Whether to force regeneration of the thumbnail.
   * @param mixed ...$options Any future extra parameters (e.g., blur_radius: 5).
   *
   * @return string The path to the generated thumbnail.
   */
  public function thumb(
    string $imgRef,
    string $action,
    ?int $width = null,
    ?int $height = null,
    int $quality = 80,
    bool $force = false,
    mixed ...$options
  ): string {
    $variantDir = $this->generateVariantDirectory($imgRef, $action, $width, $height, $quality, $options);
    $variantPath = $variantDir . DIRECTORY_SEPARATOR . basename($imgRef);

    if (!$force && $this->filesystem->exists($variantPath)) {
      return $variantPath;
    }

    // Determine whether to use Imagick or GD
    $image = extension_loaded('imagick')
      ? $this->processWithImagick($action, $imgRef, $width, $height, $options)
      : $this->processWithGd($action, $imgRef, $width, $height, $options);

    // Save the image
    return $this->saveImage($image, $variantPath, $quality);
  }

  private function processWithImagick(string $action, string $imgRef, ?int $width, ?int $height, array $options = []): Imagick
  {
    return match ($action) {
      'resize' => ImageVariantImagick::resize($imgRef, $width, $height),
      'crop' => ImageVariantImagick::crop($imgRef, $width, $height),
      'compositeBlur' => ImageVariantImagick::compositeBlur(
        $imgRef,
        $width,
        $height,
        $options['blur_radius'] ?? 5 // Example of pulling a future option
      ),
      default => throw new \InvalidArgumentException("Unknown variant action: $action"),
    };
  }

  private function processWithGd(string $action, string $imgRef, ?int $width, ?int $height, array $options = []): ImageInterface
  {
    return match ($action) {
      'resize' => ImageVariantGd::resize($imgRef, $width, $height),
      'crop' => ImageVariantGd::crop($imgRef, $width, $height),
      'compositeBlur' => ImageVariantGd::compositeBlur(
        $imgRef,
        $width,
        $height,
        $options['blur_radius'] ?? 5
      ),
      default => throw new \InvalidArgumentException("Unknown variant action: $action"),
    };
  }

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

  /**
   * Generates a folder path dynamically like: "dirname/resize_400xauto_q80_a1b2c3"
   */
  private function generateVariantDirectory(
    string $imgRef,
    string $action,
    ?int $width,
    ?int $height,
    int $quality,
    array $options = []
  ): string {
    $pathInfo = pathinfo($imgRef);

    // Fallback to "auto" if width or height are null
    $w = $width ?? 'auto';
    $h = $height ?? 'auto';

    // Create a short hash if extra options exist, preventing cache collisions
    $optionsHash = $options !== [] ? '_' . substr(md5(serialize($options)), 0, 6) : '';

    return sprintf(
      '%s/%s_%sx%s_q%d%s',
      $pathInfo['dirname'],
      $action,
      $w,
      $h,
      $quality,
      $optionsHash
    );
  }
}
