<?php
// src/Service/MediaDashboardConfig.php

namespace Kmergen\MediaBundle\Service;

use Kmergen\MediaBundle\Entity\MediaAlbum;
use Kmergen\MediaBundle\Contract\MediaAlbumOwnerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

class MediaDashboardConfig
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly TranslatorInterface $translator,
        private readonly EntityManagerInterface $em // --- ADDED ---
    ) {}

    public function build(object $entity, array $options = []): array
    {
        $context = $options['context'] ?? 'default';
        $autoSave = $options['autoSave'] ?? false;
        $album = null;

        if (isset($options['album'])) {
            $album = $options['album'];
        } elseif ($entity instanceof MediaAlbumOwnerInterface) {
            $album = $entity->getMediaAlbum($context);
            /**
             * --- NEW: Pre-create album if in autoSave mode ---
             * We proactively create and link the album here instead of in the UploadService because:
             * 1. Access to the full $entity object allows direct linking via setMediaAlbum().
             * 2. Prevents race conditions where parallel AJAX uploads might create multiple albums.
             * 3. Ensures the frontend Stimulus controller always has a valid albumId from the start.
             */
             if (!$album && $autoSave) {
                $album = new MediaAlbum();
                $this->em->persist($album);

                if (method_exists($entity, 'setMediaAlbum')) {
                    $entity->setMediaAlbum($album);
                }
                
                // Flush immediately so we have an ID for the dashboard
                $this->em->flush();
            }
        }

        $sortedMediaIds = null;
        if (array_key_exists('mediaIds', $options)) {
            $rawIds = (string) $options['mediaIds'];
            $sortedMediaIds = ($rawIds === '') ? [] : explode(',', $rawIds);
        }

        $defaults = [
            'maxFiles'      => 20,
            'maxFileSize'   => 10,
            'minWidth'      => 640,
            'minHeight'     => 480,
            'allowedMimeTypes'  => ['image/jpeg', 'image/png', 'image/webp'],
            'autoSave'      => false,
            'compact' => false,
            'title' => $this->translator->trans('dashboard.title', [], 'KmMedia'),

            'imageVariants' => [
                ['action' => 'resize', 'width' => 900], // Height is omitted (auto), quality defaults to 80
                ['action' => 'crop', 'width' => 200, 'height' => 200, 'quality' => 70]
            ],
            /**
             * Optional prefix for hidden input fields (ids, albumId, tempKey).
             * Use this to avoid name collisions when rendering multiple dashboards
             * in a single form (e.g., inside a collection).
             */
            'mediaInputPrefix' => null,
            'editableAltTextLocales' => null,
        ];

        $settings = array_merge($defaults, $options);

        if (isset($settings['editableAltTextLocales']) && is_string($settings['editableAltTextLocales'])) {
            $settings['editableAltTextLocales'] = explode(',', $settings['editableAltTextLocales']);
        }

        if (!isset($settings['tempKey'])) {
            $settings['tempKey'] = $settings['autoSave'] ? '' : uniqid('tmp_', true);
        }

        $settings['albumId'] = $album?->getId();
        $settings['context'] = $context;
        $settings['images']  = $this->mapMedia($album, $sortedMediaIds);

        $settings['translations'] = [
            'badgeText'     => $this->translator->trans('dashboard.badge_main', [], 'KmMedia'),
            'confirmDelete' => $this->translator->trans('dashboard.delete_confirm', [], 'KmMedia'),
            'errorMaxFiles' => $this->translator->trans(
                'dashboard.error.max_files',
                ['count' => (int) $settings['maxFiles']],
                'KmMedia'
            ),
            'errorFileSize' => $this->translator->trans('dashboard.error.file_size', [], 'KmMedia'),
            'errorFileType' => $this->translator->trans('dashboard.error.file_type', [], 'KmMedia'),
            'errorMinResolution' => $this->translator->trans(
                'dashboard.error.min_resolution',
                ['width' => $settings['minWidth'], 'height' => $settings['minHeight']],
                'KmMedia'
            ),
            'btnCancel'     => $this->translator->trans('dashboard.buttons.cancel', [], 'KmMedia'),
            'btnSave'       => $this->translator->trans('dashboard.buttons.save', [], 'KmMedia'),
            'errorUpload'   => $this->translator->trans('dashboard.error.upload_failed', [], 'KmMedia'),
        ];

        return $settings;
    }

    private function mapMedia(?MediaAlbum $album, ?array $sortedIds = null): array
    {
        if (!$album) {
            return [];
        }

        $mediaCollection = $album->getMedia();
        $mapped = [];

        $mediaById = [];
        foreach ($mediaCollection as $m) {
            $mediaById[$m->getId()] = $m;
        }

        if ($sortedIds !== null) {
            foreach ($sortedIds as $id) {
                if (isset($mediaById[$id])) {
                    $m = $mediaById[$id];
                    $mapped[] = $this->createMediaArray($m);
                }
            }
        } else {
            foreach ($mediaCollection as $m) {
                if ($m->getTempKey() !== null) {
                    continue;
                }
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
            // --- NEW: Updated to new thumb() signature ---
            'previewUrl' => '/' . ltrim($this->imageService->thumb($m->getUrl(), 'crop', 150, 150, 80), '/')
        ];
    }
}
