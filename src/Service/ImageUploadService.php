<?php

namespace Kmergen\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
use Kmergen\MediaBundle\Entity\MediaAlbum;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;

class ImageUploadService
{
    private string $publicDir;

    public function __construct(
        private readonly EntityManagerInterface $em, 
        KernelInterface $kernel
    ) {
        $this->publicDir = $kernel->getProjectDir() . '/public';
    }

    public function upload(Request $request): Media
    {
        /** @var UploadedFile $file */
        $file = $request->files->get('file');
        $targetMediaDir = $request->request->get('targetMediaDir');
        $albumId = $request->request->get('albumId');
        $tempKey = $request->request->get('tempKey');
        if (!$tempKey) {
            $tempKey = null;
        }
        // 1. Album finden oder neu erstellen
        $album = null;
        if ($albumId) {
            $album = $this->em->getRepository(MediaAlbum::class)->find($albumId);
        }

        if (!$album) {
            $album = new MediaAlbum();
            $this->em->persist($album);
            // Wir flushen hier noch nicht, damit Media und Album in einer Transaktion landen
        }

        // 2. Datei-Infos sammeln
        $fileSize = $file->getSize();
        $imageSize = getimagesize($file->getPathname());
        $fileDimension = $imageSize[0] . 'x' . $imageSize[1];
        $originalFilename = $file->getClientOriginalName();
        $newFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $file->guessExtension();

        // 3. Neue Media Entity erstellen
        $media = new Media();
        $media->setName($originalFilename);
        $media->setMime($file->getClientMimeType());
        $media->setSize($fileSize);
        $media->setDimension($fileDimension);
        $media->setTempKey($tempKey);

        // Relation setzen
        $media->setAlbum($album);

        // Position automatisch berechnen (innerhalb des Albums)
        $media->setPosition($album->getMedia()->count() + 1);

        // 4. Persistieren
        $this->em->persist($media);
        $this->em->flush(); // Jetzt haben wir eine Media-ID für den Ordnerpfad

        // 5. Verzeichnis & URL generieren
        // Wir nutzen die Media-ID für die Unterordner-Struktur
        $finalTargetDir = $this->publicDir . DIRECTORY_SEPARATOR . $targetMediaDir . DIRECTORY_SEPARATOR . $media->getId();
        $media->setUrl($targetMediaDir . '/' . $media->getId() . '/' . $newFilename);

        $this->em->flush();

        // 6. Datei physisch verschieben
        $file->move($finalTargetDir, $newFilename);

        return $media;
    }
}
