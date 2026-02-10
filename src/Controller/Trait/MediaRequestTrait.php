<?php

namespace Kmergen\MediaBundle\Controller\Trait;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\MediaAlbum;
use Kmergen\MediaBundle\Service\MediaDashboardConfig;
use Kmergen\MediaBundle\Service\MediaPersistenceService;
use Symfony\Component\HttpFoundation\Request;

trait MediaRequestTrait
{
    /**
     * Verarbeitet die Media-Daten aus dem Request, finalisiert das Album
     * und verknüpft es mit der Entity.
     */
    public function handleMediaUpload(
        Request $request,
        object $entity, // z.B. Breeder
        EntityManagerInterface $em,
        MediaPersistenceService $persistenceService
    ): void {
        $albumId = $request->request->get('mediaAlbumId');
        $mediaIdsString = $request->request->get('mediaIds');

        // 1. Album laden (Entweder das existierende oder das neu erstellte aus dem Upload)
        $album = null;

        // Hat die Entity schon ein Album?
        if (method_exists($entity, 'getMediaAlbum') && $entity->getMediaAlbum()) {
            $album = $entity->getMediaAlbum();
        }
        // Wenn nicht, schauen wir, ob im Request eine ID steht (neuer Upload)
        elseif ($albumId) {
            $album = $em->getRepository(MediaAlbum::class)->find($albumId);

            // Verknüpfung herstellen, falls Entity Setter hat
            if ($album && method_exists($entity, 'setMediaAlbum')) {
                $entity->setMediaAlbum($album);
            }
        }

        // 2. Finalisieren (Aufräumen, Sortieren, TempKeys entfernen)
        // Wir machen das nur, wenn ein Album da ist UND (wichtig!) das Formular valide war.
        // Da diese Methode meist IN der if($form->isValid()) Schleife aufgerufen wird, passt das.
        if ($album) {
            // Hinweis: mediaIdsString kann null sein, wenn nichts geändert wurde, 
            // oder ein leerer String "", wenn alles gelöscht wurde.
            // Der PersistenceService muss damit umgehen können.
            $persistenceService->finalize($album, $mediaIdsString);
        }
    }

    /**
     * Baut die Dashboard-Config für den Fall eines Validation-Errors (oder Initial)
     * und stellt den vorherigen Zustand wieder her.
     * 
     * @param array $customOptions Hier kannst du 'maxFiles', 'imageVariants' etc. übergeben.
     */
    public function getMediaDashboardConfig(
        Request $request,
        object $entity,
        MediaDashboardConfig $configService,
        EntityManagerInterface $em,
        array $customOptions = [] // <--- NEU: Ermöglicht Überschreiben von Optionen
    ): array {

        // 1. Basis-Optionen (Standard)
        $defaultOptions = [
            'autoSave' => false,
            'context' => 'default',
        ];

        // 2. Deine Custom-Optionen mit den Defaults mergen
        // Deine Optionen ($customOptions) gewinnen hier.
        $options = array_merge($defaultOptions, $customOptions);

        // --- STATE RECOVERY (Wiederherstellung bei Formular-Fehlern) ---
        // POST-Daten haben die allerhöchste Priorität, damit der User-Input nicht verloren geht.

        if ($request->isMethod('POST')) {
            $submittedAlbumId = $request->request->get('mediaAlbumId');
            $submittedMediaIds = $request->request->get('mediaIds');
            $submittedTempKey = $request->request->get('mediaTempKey');

            // TempKey wiederherstellen
            if ($submittedTempKey) {
                $options['tempKey'] = $submittedTempKey;
            }

            // Album wiederherstellen
            if ($submittedAlbumId) {
                $options['album'] = $em->getRepository(MediaAlbum::class)->find($submittedAlbumId);
            }

            // Sortierung wiederherstellen
            if ($submittedMediaIds !== null) {
                $options['mediaIds'] = $submittedMediaIds;
            }
        }

        return $configService->build($entity, $options);
    }
}
