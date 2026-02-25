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
        object $entity,
        EntityManagerInterface $em,
        MediaPersistenceService $persistenceService,
        ?string $inputPrefix = null // NEU: Optionaler Prefix z.B. "amenity_media[0]"
    ): void {
        // Daten aus dem Request extrahieren
        if ($inputPrefix) {
            // Extrahiert "ids" und "albumId" aus dem verschachtelten Array (z.B. amenity_media[0][ids])
            $parts = $this->parseInputPrefix($inputPrefix);
            $rootData = $request->request->all($parts['root']);
            $mediaData = $rootData[$parts['index']] ?? [];

            $albumId = $mediaData['albumId'] ?? null;
            $mediaIdsString = $mediaData['ids'] ?? null;
        } else {
            $albumId = $request->request->get('mediaAlbumId');
            $mediaIdsString = $request->request->get('mediaIds');
        }

        $album = null;
        if (method_exists($entity, 'getMediaAlbum') && $entity->getMediaAlbum()) {
            $album = $entity->getMediaAlbum();
        } elseif ($albumId) {
            $album = $em->getRepository(MediaAlbum::class)->find($albumId);
            if ($album && method_exists($entity, 'setMediaAlbum')) {
                $entity->setMediaAlbum($album);
            }
        }

        if ($album) {
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
        array $customOptions = []
    ): array {
        $options = array_merge(['autoSave' => false, 'context' => 'default'], $customOptions);
        $inputPrefix = $options['mediaInputPrefix'] ?? null;

        if ($request->isMethod('POST')) {
            $mediaData = [];
            if ($inputPrefix) {
                $parts = $this->parseInputPrefix($inputPrefix);
                $rootData = $request->request->all($parts['root']);
                $mediaData = $rootData[$parts['index']] ?? [];
            } else {
                $mediaData = [
                    'albumId' => $request->request->get('mediaAlbumId'),
                    'ids' => $request->request->get('mediaIds'),
                    'tempKey' => $request->request->get('mediaTempKey'),
                ];
            }

            if (!empty($mediaData['tempKey'])) $options['tempKey'] = $mediaData['tempKey'];
            if (!empty($mediaData['albumId'])) $options['album'] = $em->getRepository(MediaAlbum::class)->find($mediaData['albumId']);
            if (isset($mediaData['ids'])) $options['mediaIds'] = $mediaData['ids'];
        }

        return $configService->build($entity, $options);
    }

    /**
     * Hilfsmethode um "amenity_media[0]" in ['root' => 'amenity_media', 'index' => 0] zu zerlegen.
     */
    private function parseInputPrefix(string $prefix): array
    {
        preg_match('/^([^\[]+)\[([^\]]+)\]/', $prefix, $matches);
        return [
            'root' => $matches[1] ?? $prefix,
            'index' => $matches[2] ?? null
        ];
    }
}
