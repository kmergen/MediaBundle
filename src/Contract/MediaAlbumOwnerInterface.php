<?php

namespace Kmergen\MediaBundle\Contract;

use Kmergen\MediaBundle\Entity\MediaAlbum;

interface MediaAlbumOwnerInterface
{
    /**
     * Gibt das Album für einen bestimmten Kontext zurück.
     * @param string $context z.B. 'main', 'gallery', 'documents'
     */
    public function getMediaAlbum(string $context = 'default'): ?MediaAlbum;

    /**
     * Setzt das Album für einen bestimmten Kontext.
     * WICHTIG: Das ? erlaubt auch null, um ein Album zu entfernen (detachen).
     */
    public function setMediaAlbum(?MediaAlbum $album, string $context = 'default'): void;
}
