<?php

namespace Kmergen\MediaBundle\Interface;

use Kmergen\MediaBundle\Entity\MediaAlbum;

interface MediaAlbumOwnerInterface
{
    /**
     * Gibt das Album für einen bestimmten Kontext zurück.
     * Diese Interface muss in jeder Project Entiy implementiert werden,
     * um das MediaBundle zu nutzen.
     * @param string $context z.B. 'main', 'gallery', 'documents'
     */
    public function getMediaAlbum(string $context = 'default'): ?MediaAlbum;

    /**
     * Setzt das Album für einen bestimmten Kontext (wichtig für Upload/Erstellung).
     */
    public function setMediaAlbum(MediaAlbum $album, string $context = 'default'): void;
}
