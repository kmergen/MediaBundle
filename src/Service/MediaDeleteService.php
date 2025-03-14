<?php
// src/Service/MediaDeleteService.php
namespace Kmergen\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpKernel\KernelInterface;

class MediaDeleteService
{
  private string $publicDir;

  public function __construct(
    private readonly EntityManagerInterface $em,
    private readonly KernelInterface $kernel,
    private readonly Filesystem $filesystem,
    private readonly LoggerInterface $logger
  ) {
    $this->publicDir = Path::join($kernel->getProjectDir(), 'public');
  }

  /**
   * Deletes a media entity and its associated file from the filesystem.
   */
  public function deleteMedia(Media $media): void
  {
    // Delete the file from the filesystem
    $mediaDir = Path::join($this->publicDir, $media->getUrl());
    $mediaDir = dirname($mediaDir); // Get the directory path
    try {
      if ($this->filesystem->exists($mediaDir)) {
        $this->filesystem->remove($mediaDir);
        $this->logger->info("Deleted media directory: {$mediaDir}");
      } else {
        $this->logger->warning("Media directory not found: {$mediaDir}");
      }
    } catch (\Exception $e) {
      $this->logger->error("Failed to delete media directory: {$e->getMessage()}");
    }

    // Remove the media entity from the database
    $this->em->remove($media);
    $this->em->flush();
  }

  /**
   * Deletes multiple media entities and their associated files.
   */
  public function deleteMultipleMedia(array $mediaEntities): void
  {
    foreach ($mediaEntities as $media) {
      $this->deleteMedia($media);
    }
  }
}