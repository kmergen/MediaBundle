<?php

namespace Kmergen\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
use Kmergen\MediaBundle\Entity\MediaAlbum;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

class MediaPersistenceService
{
    private string $publicDir;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Filesystem $filesystem,
        KernelInterface $kernel
    ) {
        $this->publicDir = $kernel->getProjectDir() . '/public';
    }

    /**
     * Macht alle temporären Bilder eines Albums permanent.
     * Aufzurufen, nachdem das Haupt-Formular (z.B. Project) erfolgreich validiert wurde.
     */
    public function finalizeUploads(MediaAlbum $album): void
    {
        $mediaCollection = $album->getMedia();
        $needsFlush = false;

        foreach ($mediaCollection as $media) {
            // Wir suchen nur Bilder, die noch "temporär" sind
            if ($media->getTempKey() !== null) {
                $media->setTempKey(null);
                $needsFlush = true;
            }
        }

        if ($needsFlush) {
            $this->em->flush();
        }
    }

    /**
     * Löscht alte, nicht gespeicherte Medien (für den Cronjob).
     * Löscht physische Dateien und Datenbank-Einträge.
     *
     * @param int $retentionHours Wie alt dürfen Temp-Bilder sein (in Stunden)?
     * @return int Anzahl der gelöschten Bilder
     */
    public function cleanupOrphanedMedia(int $retentionHours = 24): int
    {
        $limitDate = new \DateTime(sprintf('-%d hours', $retentionHours));

        // Repository holen
        $repo = $this->em->getRepository(Media::class);

        // Query: Finde alle Media, die einen tempKey haben UND älter als X sind
        $qb = $repo->createQueryBuilder('m')
            ->where('m.tempKey IS NOT NULL')
            ->andWhere('m.createdAt < :limitDate')
            ->setParameter('limitDate', $limitDate);

        /** @var Media[] $orphanedMedia */
        $orphanedMedia = $qb->getQuery()->getResult();
        $deletedCount = 0;

        foreach ($orphanedMedia as $media) {
            // 1. Datei vom Dateisystem löschen
            // Pfad rekonstruieren (Achtung: Logik muss zu deinem UploadService passen!)
            // Wenn url z.B. "uploads/projects/123/bild.jpg" ist:
            $filePath = $this->publicDir . DIRECTORY_SEPARATOR . ltrim($media->getUrl(), '/');
            
            // Optional: Auch den Ordner löschen, wenn er leer ist? 
            // Hier löschen wir erstmal nur das File.
            if ($this->filesystem->exists($filePath)) {
                $this->filesystem->remove($filePath);
            }

            // 2. Datenbankeintrag entfernen
            $this->em->remove($media);
            $deletedCount++;
        }

        if ($deletedCount > 0) {
            $this->em->flush();
        }

        return $deletedCount;
    }
}