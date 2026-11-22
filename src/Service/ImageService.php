<?php

// src/Service/ImageService.php

namespace Kmergen\MediaBundle\Service;

use Imagick;
use Kmergen\MediaBundle\Component\ImageVariantImagick;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

readonly class ImageService
{
  private string $publicDir;

  public function __construct(
    private Filesystem $filesystem,
    private LoggerInterface $logger,
    #[Autowire('%kernel.project_dir%')]
    string $projectDir,
  ) {
    $this->publicDir = Path::join($projectDir, 'public');
  }

  /**
   * Generates a thumbnail for the given image reference.
   *
   * @param string $imgRef   The path of the original image relative to the public directory
   *                         (e.g. "uploads/10/456/photo.jpg", a leading slash is allowed).
   * @param string $action   The action to perform (e.g., 'resize', 'crop').
   * @param int|null $width  The target width (null for auto-calculation).
   * @param int|null $height The target height (null for auto-calculation).
   * @param int $quality     The image quality (1-100). Default is 80.
   * @param bool $force      Whether to force regeneration of the thumbnail.
   * @param mixed ...$options Any future extra parameters (e.g., blur_radius: 5).
   *
   * @return string The path of the thumbnail relative to the public directory, in the same form as
   *                $imgRef. If the original is missing or cannot be processed, $imgRef is returned
   *                and a warning is logged, so a single broken image cannot take down a whole page.
   *
   * Files are resolved against the public directory, not the current working directory, which
   * differs between web servers (PHP-FPM, CGI, the built-in server) and the console.
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
    $variantPath = $variantDir . '/' . basename($imgRef);

    $absoluteOriginal = $this->absolutePath($imgRef);
    $absoluteVariant = $this->absolutePath($variantPath);

    if (null === $absoluteOriginal || null === $absoluteVariant) {
      $this->logger->warning('Thumbnail of "{path}" refused: the path leaves the public directory.', ['path' => $imgRef]);

      return $imgRef;
    }

    if (!$force && $this->filesystem->exists($absoluteVariant)) {
      return $variantPath;
    }

    if (!is_file($absoluteOriginal)) {
      $this->logger->warning('Thumbnail of "{path}" not generated: the original file is missing.', ['path' => $imgRef]);

      return $imgRef;
    }

    try {
      // Imagick is required (ext-imagick in composer.json).
      $image = $this->processWithImagick($action, $absoluteOriginal, $width, $height, $options);
      $this->saveImage($image, $absoluteVariant, $quality);
    } catch (\ImagickException|IOExceptionInterface $exception) {
      $this->logger->warning('Thumbnail of "{path}" not generated: {message}', [
        'path' => $imgRef,
        'message' => $exception->getMessage(),
        'exception' => $exception,
      ]);

      return $imgRef;
    }

    return $variantPath;
  }

  /**
   * Absolute path below the public directory, or null if the path would leave it.
   */
  private function absolutePath(string $relativePath): ?string
  {
    $absolute = Path::join($this->publicDir, ltrim($relativePath, '/'));

    return Path::isBasePath($this->publicDir, $absolute) ? $absolute : null;
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

  private function saveImage(Imagick $image, string $path, int $quality): void
  {
    $this->filesystem->mkdir(dirname($path));

    $image->setImageCompressionQuality($quality);
    $image->writeImage($path);
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
