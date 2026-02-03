<?php

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
   * Löscht ein einzelnes Media Entity und die Dateien.
   * $flush = false ist nützlich für Massenlöschungen.
   */
  public function deleteMedia(Media $media, bool $flush = true): void
  {
    // 1. Filesystem Pfad ermitteln
    // Wir gehen davon aus: public/uploads/media/{id}/image.jpg
    $fullPath = Path::join($this->publicDir, $media->getUrl());
    $directoryToDelete = dirname($fullPath);

    // --- SICHERHEITSCHECK ---
    // Wir prüfen, ob der Ordnername wirklich der ID entspricht.
    // Das verhindert, dass wir "uploads/" löschen, falls die Struktur flach ist.
    $folderName = basename($directoryToDelete);

    if ((string)$media->getId() === $folderName) {
      try {
        if ($this->filesystem->exists($directoryToDelete)) {
          $this->filesystem->remove($directoryToDelete);
          $this->logger->info("Deleted media directory: {$directoryToDelete}");
        }
      } catch (\Exception $e) {
        // Wir loggen nur und werfen nicht, damit der DB-Eintrag trotzdem gelöscht wird (keine Broken Links)
        $this->logger->error("Failed to delete media files: {$e->getMessage()}");
      }
    } else {
      // Fallback: Wenn keine ID-Ordnerstruktur existiert, löschen wir NUR die Datei, nicht den Ordner!
      try {
        if ($this->filesystem->exists($fullPath)) {
          $this->filesystem->remove($fullPath);
          $this->logger->info("Deleted media file only (unsafe dir structure): {$fullPath}");
        }
      } catch (\Exception $e) {
        $this->logger->error("Failed to delete media file: {$e->getMessage()}");
      }
    }

    // 2. Datenbank bereinigen
    $this->em->remove($media);

    if ($flush) {
      $this->em->flush();
    }
  }

  public function deleteMultipleMedia(array $mediaEntities): void
  {
    foreach ($mediaEntities as $media) {
      // Flush erst ganz am Ende -> Performance Boost
      $this->deleteMedia($media, false);
    }
    $this->em->flush();
  }
}
