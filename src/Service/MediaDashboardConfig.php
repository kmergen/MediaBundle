<?php

namespace Kmergen\MediaBundle\Service;

use Kmergen\MediaBundle\Service\ImageService;
use Kmergen\MediaBundle\Interface\MediaAlbumOwnerInterface;

use ReflectionClass;

class MediaDashboardConfig
{
    public function __construct(private readonly ImageService $imageService) {}

    public function build(object $entity, array $options = []): array
    {
        $context = $options['context'] ?? 'default';
        $album = null;

        if ($entity instanceof MediaAlbumOwnerInterface) {
            $album = $entity->getMediaAlbum($context);
        }

        // WICHTIG: Die Identität des Owners festlegen
        $ownerData = [
            'ownerClass' => (new ReflectionClass($entity))->getName(), // Voller Namespace für Doctrine
            'ownerId'    => $entity->getId(), // Kann null sein bei 'new'
            'context'    => $context,
            'albumId'    => $album?->getId(),
            'images'     => $this->mapMedia($album),
        ];

        $configDefaults = [
            'maxFiles'      => 20,
            'autoSave'      => true,
            'title'         => 'Medien verwalten',
            'imageVariants' => ['resize,900,0,80', 'crop,200,200,70'],
        ];

        return array_merge($configDefaults, $options, $ownerData);
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
