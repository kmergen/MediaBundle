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

        return array_merge([
            'maxFiles'      => 20,
            'autoSave'      => false,
            'title'         => 'Medien verwalten',
            'imageVariants' => ['resize,900,0,80', 'crop,200,200,70'],
            'tempKey'       => 'tmp_' . bin2hex(random_bytes(8)),
        ], $options, [
            'albumId' => $album?->getId(),
            'context' => $context,
            'images'  => $this->mapMedia($album),
        ]);
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
        } else {
            return [];
        }
        return $mapped;
    }
}
