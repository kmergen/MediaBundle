<?php
// src/Service/MediaDashboardConfig.php

namespace Kmergen\MediaBundle\Service;

use Kmergen\MediaBundle\Entity\MediaAlbum;
use Kmergen\MediaBundle\Contract\MediaAlbumOwnerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MediaDashboardConfig
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly TranslatorInterface $translator
    ) {}

    public function build(object $entity, array $options = []): array
    {
        $context = $options['context'] ?? 'default';
        $album = null;

        // 1. Album ermitteln
        if (isset($options['album'])) {
            $album = $options['album'];
        } elseif ($entity instanceof MediaAlbumOwnerInterface) {
            $album = $entity->getMediaAlbum($context);
        }

        // 2. Sortier-Logik vorbereiten
        $sortedMediaIds = null;
        if (array_key_exists('mediaIds', $options)) {
            $rawIds = (string) $options['mediaIds'];
            $sortedMediaIds = ($rawIds === '') ? [] : explode(',', $rawIds);
        }

        // 3. Defaults definieren
        $defaults = [
            'maxFiles'      => 10,
            'maxFileSize'   => 50, // Neu: Standard 10 MB
            'allowedMimeTypes'  => ['image/jpeg', 'image/png', 'image/webp'], // Erlaubte MIME-Types
            'autoSave'      => false, // Hier ist der Standardwert
            'title'         => 'Medien verwalten',
            'imageVariants' => ['resize,900,0,80', 'crop,200,200,70'],
            // Null bedeutet: Standardverhalten (Nur aktuelle User-Sprache)
            'editableAltTextLocales' => null,
        ];

        // 4. User-Optionen mit Defaults mergen
        // Jetzt ist $settings['autoSave'] garantiert vorhanden (entweder aus User-Input oder Default)
        $settings = array_merge($defaults, $options);

        // 5. Falls der User 'de,en' als String übergeben hat, machen wir ein Array daraus.
        if (isset($settings['editableAltTextLocales']) && is_string($settings['editableAltTextLocales'])) {
            // Wir unterstützen Komma-getrennte Strings
            $settings['editableAltTextLocales'] = explode(',', $settings['editableAltTextLocales']);
        }


        // 6. TempKey Logik NACH dem Merge anwenden
        if (!isset($settings['tempKey'])) {
            // Wenn autoSave an ist, brauchen wir keinen TempKey (leerer String).
            // Sonst generieren wir einen neuen.
            $settings['tempKey'] = $settings['autoSave'] ? '' : uniqid('tmp_', true);
        }

        // 7. Restliche dynamische Werte hinzufügen
        $settings['albumId'] = $album?->getId();
        $settings['context'] = $context;
        $settings['images']  = $this->mapMedia($album, $sortedMediaIds);

        // Translations
        $settings['translations'] = [
            'badgeText'     => $this->translator->trans('dashboard.badge_main', [], 'KmMedia'),
            'confirmDelete' => $this->translator->trans('dashboard.delete_confirm', [], 'KmMedia'),
             'errorMaxFiles' => $this->translator->trans('dashboard.error.max_files', ['%count%' => $settings['maxFiles']], 'KmMedia'),
            'errorFileSize' => $this->translator->trans('dashboard.error.file_size', [], 'KmMedia'), // Platzhalter werden im JS ersetzt
            'errorFileType' => $this->translator->trans('dashboard.error.file_type', [], 'KmMedia'),
            'btnCancel'     => $this->translator->trans('dashboard.buttons.cancel', [], 'KmMedia'),
            'btnSave'       => $this->translator->trans('dashboard.buttons.save', [], 'KmMedia'),
            'errorUpload' => $this->translator->trans('dashboard.error.upload_failed', [], 'KmMedia'),
        ];

        return $settings;
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
        // Hier wollen wir ALLE angeforderten Bilder sehen (auch die gerade hochgeladenen Temp-Bilder!)
        if ($sortedIds !== null) {
            foreach ($sortedIds as $id) {
                if (isset($mediaById[$id])) {
                    $m = $mediaById[$id];
                    $mapped[] = $this->createMediaArray($m);
                }
            }
        }
        // FALL B: Standard DB Reihenfolge (Initial Load)
        else {
            foreach ($mediaCollection as $m) {
                // --- ÄNDERUNG START ---

                // Wenn wir die Seite frisch laden (kein Validation Error), 
                // wollen wir KEINE temporären Dateien sehen, die evtl. noch als "Müll" im Album liegen.
                // Nur Bilder anzeigen, die permanent gespeichert sind (tempKey === null).
                if ($m->getTempKey() !== null) {
                    continue;
                }

                // --- ÄNDERUNG ENDE ---

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
