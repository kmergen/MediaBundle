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
     * Finalisiert das Album:
     * 1. Löscht Bilder, die im UI entfernt wurden (nicht in $mediaIds enthalten).
     * 2. Entfernt den tempKey bei den verbleibenden Bildern (macht sie permanent).
     * 3. Speichert die Sortierung basierend auf der Reihenfolge in $mediaIds.
     */
    public function finalize(MediaAlbum $album, ?string $mediaIds): void
    {
        // 1. IDs aus dem Request in ein Array umwandeln (z.B. ['5', '8', '6'])
        $orderedIds = $mediaIds ? explode(',', $mediaIds) : [];

        // 2. Alle aktuellen Bilder des Albums durchgehen
        // Wir iterieren über eine Kopie (toArray), damit wir sicher entfernen können
        foreach ($album->getMedia()->toArray() as $media) {
            $id = (string) $media->getId();

            // Ist das Bild in der Liste der zu speichernden IDs?
            if (in_array($id, $orderedIds, true)) {
                // JA: Behalten und finalisieren
                $media->setTempKey(null); // Bild permanent machen

                // Position setzen (Index im Array entspricht der Sortierung)
                $position = array_search($id, $orderedIds, true);
                $media->setPosition($position);
            } else {
                // NEIN: Bild wurde im UI entfernt -> Löschen
                $this->em->remove($media);
                $album->removeMedium($media);
            }
        }

        $this->em->flush();
    }

    /**
     * Macht alle temporären Bilder eines Albums permanent.
     * Aufzurufen, nachdem das Haupt-Formular (z.B. Project) erfolgreich validiert wurde.
     */
    // public function finalizeUploads(MediaAlbum $album): void
    // {
    //     $medias = $album->getMedia();
    //     $needsFlush = false;

    //     foreach ($medias as $media) {
    //         // Wir suchen nur Bilder, die noch "temporär" sind
    //         if ($media->getTempKey() !== null) {
    //             $media->setTempKey(null);
    //             $needsFlush = true;
    //         }
    //     }

    //     if ($needsFlush) {
    //         $this->em->flush();
    //     }
    // }

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
