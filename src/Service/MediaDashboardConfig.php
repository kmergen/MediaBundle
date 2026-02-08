<?php

namespace Kmergen\MediaBundle\Service;

use Kmergen\MediaBundle\Service\ImageService;
use ReflectionClass;

class MediaDashboardConfig
{
    public function __construct(private readonly ImageService $imageService) {}

    public function build(object $entity, array $options = []): array
    {
        $album = method_exists($entity, 'getMediaAlbum') ? $entity->getMediaAlbum() : null;

        // 1. FESTE DATEN (Darf nicht überschrieben werden)
        $fixedData = [
            'albumId'  => $album?->getId(),
            'entityId' => method_exists($entity, 'getId') ? $entity->getId() : null,
            'images'   => $this->mapMedia($album),
        ];

        // 2. KONFIGURATION (Darf überschrieben werden)
        $configDefaults = [
            'targetMediaDir' => 'images/' . strtolower((new ReflectionClass($entity))->getShortName()) . '/' . $entity->getId(),
            'maxFiles'       => 20,
            'autoSave'       => true,
            'title'          => 'Medien verwalten',
            'imageVariants'  => ['resize,900,0,80', 'crop,200,200,70'],
        ];

        $userConfig = array_merge($configDefaults, $options);
        return array_merge($userConfig, $fixedData);
    }

    private function mapMedia($album): array
    {
        if ($album?->getId() !== null) {
            $medias = $album->getMedia();
            $mapped = [];
            foreach ($medias as $media) {
                $mapped[] = [
                    'id' => $media->getId(),
                    'url' => '/' . ltrim($media->getUrl(), '/'),
                    'name' => $media->getName(),
                    'previewUrl' => '/' . ltrim($this->imageService->thumb($media->getUrl(), 'crop,150,150,80'), '/')
                ];
            }
        }
        return $mapped;
    }
}
