<?php
// src/Service/MediaDashboardConfig.php

namespace Kmergen\MediaBundle\Service;

use Kmergen\MediaBundle\Entity\MediaAlbum;
use Kmergen\MediaBundle\Interface\MediaAlbumOwnerInterface;

class MediaDashboardConfig
{
    public function __construct(private readonly ImageService $imageService) {}

    public function build(object $entity, array $options = []): array
    {
        $context = $options['context'] ?? 'default';
        $album = null;

        // 1. Album ermitteln (Entweder aus Entity oder explizit übergeben)
        if (isset($options['album'])) {
            $album = $options['album'];
        } elseif ($entity instanceof MediaAlbumOwnerInterface) {
            $album = $entity->getMediaAlbum($context);
        }

        // 2. Sortier-Logik vorbereiten
        $sortedMediaIds = null;
        // Wir prüfen mit array_key_exists, damit wir auch einen leeren String ("") abfangen
        if (array_key_exists('mediaIds', $options)) {
            $rawIds = (string) $options['mediaIds'];

            if ($rawIds === '') {
                // User hat alles gelöscht -> Leeres Array
                $sortedMediaIds = [];
            } else {
                // User hat sortiert -> IDs parsen
                $sortedMediaIds = explode(',', $rawIds);
            }
        }

        return array_merge([
            'maxFiles'      => 20,
            'autoSave'      => false,
            'title'         => 'Medien verwalten',
            'imageVariants' => ['resize,900,0,80', 'crop,200,200,70'],
            // TempKey generieren wir nur, wenn keiner übergeben wurde
            'tempKey'       => $options['tempKey'] ?? ($options['autoSave'] ? '' : uniqid('tmp_', true)),
        ], $options, [
            'albumId' => $album?->getId(),
            'context' => $context,
            // Hier übergeben wir jetzt das Album UND die Sortierung
            'images'  => $this->mapMedia($album, $sortedMediaIds),
        ]);
    }

    /**
     * Mappt die Bilder. Wenn $sortedIds übergeben wird,
     * wird diese Reihenfolge respektiert und Bilder, die nicht darin vorkommen, ausgeblendet.
     */
    private function mapMedia(?MediaAlbum $album, ?array $sortedIds = null): array
    {
        if (!$album) {
            return [];
        }

        $mediaCollection = $album->getMedia();
        $mapped = [];

        // Helper um Media nach ID zu finden
        $mediaById = [];
        foreach ($mediaCollection as $m) {
            $mediaById[$m->getId()] = $m;
        }

        // FALL A: Wir haben eine explizite Sortierung vom Frontend (Validation Error Case)
        if ($sortedIds !== null) {
            foreach ($sortedIds as $id) {
                if (isset($mediaById[$id])) {
                    $m = $mediaById[$id];
                    $mapped[] = $this->createMediaArray($m);
                }
            }
        }
        // FALL B: Standard DB Reihenfolge (Initial Load oder AutoSave)
        else {
            foreach ($mediaCollection as $m) {
                $mapped[] = $this->createMediaArray($m);
            }
        }

        return $mapped;
    }

    private function createMediaArray($m): array
    {
        return [
            'id' => $m->getId(),
            'url' => '/' . ltrim($m->getUrl(), '/'),
            'name' => $m->getName(),
            // Hier sicherstellen, dass ImageService Exceptions abgefangen werden oder URL valid ist
            'previewUrl' => '/' . ltrim($this->imageService->thumb($m->getUrl(), 'crop,150,150,80'), '/')
        ];
    }
}
