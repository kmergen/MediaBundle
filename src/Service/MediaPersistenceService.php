<?php

namespace Kmergen\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
use Kmergen\MediaBundle\Entity\MediaAlbum;

class MediaPersistenceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaDeleteService $mediaDeleteService // <--- NEU: Wir nutzen den Experten fürs Löschen
    ) {}

    /**
     * Finalisiert das Album:
     * 1. Löscht Bilder, die im UI entfernt wurden (nicht in $mediaIds enthalten).
     * 2. Entfernt den tempKey bei den verbleibenden Bildern (macht sie permanent).
     * 3. Speichert die Sortierung basierend auf der Reihenfolge in $mediaIds.
     */
    public function finalize(MediaAlbum $album, ?string $mediaIds): void
    {
        // IDs aus dem Request parsen
        $orderedIds = $mediaIds ? array_filter(explode(',', $mediaIds)) : [];
        $orderedIdsMap = array_flip($orderedIds);

        // Über eine KOPIE iterieren, damit wir sicher entfernen können
        foreach ($album->getMedia()->toArray() as $media) {
            $id = (string) $media->getId();

            if (isset($orderedIdsMap[$id])) {
                // JA: Behalten
                $media->setTempKey(null); // Permanent machen
                $media->setPosition($orderedIdsMap[$id]); // Sortieren
            } else {
                // NEIN: Bild wurde im UI entfernt -> Löschen (Datei UND Datenbank)

                // --- KORREKTUR: ---
                // Wir nutzen den Service, der auch die Datei von der Platte putzt.
                // false = kein sofortiges Flush (machen wir am Ende gesammelt)
                $this->mediaDeleteService->deleteMedia($media, false);

                // Verbindung im Objekt lösen (wichtig für Doctrine Cache im gleichen Request)
                $album->removeMedium($media);
            }
        }

        $this->em->flush();
    }

    /**
     * Löscht alte, nicht gespeicherte Medien (für den Cronjob).
     * (Refactored: Nutzt jetzt auch den MediaDeleteService -> weniger Code-Duplizierung!)
     */
    public function cleanupOrphanedMedia(int $retentionHours = 24): int
    {
        $limitDate = new \DateTime(sprintf('-%d hours', $retentionHours));
        $repo = $this->em->getRepository(Media::class);

        $orphanedMedia = $repo->createQueryBuilder('m')
            ->where('m.tempKey IS NOT NULL')
            ->andWhere('m.createdAt < :limitDate')
            ->setParameter('limitDate', $limitDate)
            ->getQuery()
            ->getResult();

        $deletedCount = 0;

        foreach ($orphanedMedia as $media) {
            // Auch hier nutzen wir jetzt den Service -> Datei + DB weg
            $this->mediaDeleteService->deleteMedia($media, false);
            $deletedCount++;
        }

        if ($deletedCount > 0) {
            $this->em->flush();
        }

        return $deletedCount;
    }
}
