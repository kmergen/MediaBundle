<?php

namespace Kmergen\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
use Kmergen\MediaBundle\Entity\MediaAlbum;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Kmergen\MediaBundle\Interface\MediaAlbumOwnerInterface;

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
        $albumId = $request->request->get('albumId');
        $context = $request->request->get('context', 'default');
        $tempKey = $request->request->get('tempKey');
        $autoSave = $request->request->get('autoSave') === 'true';


        // 1. Album-Handling
        $album = $albumId ? $this->em->getRepository(MediaAlbum::class)->find($albumId) : null;
        if (!$album) {
            $album = new MediaAlbum();
            $this->em->persist($album);
            $this->em->flush(); // ID generieren
        }

        // 2. Media-Handling
        $media = new Media();
        $media->setAlbum($album);
        $media->setName($file->getClientOriginalName());
        $media->setMime($file->getClientMimeType());
        $media->setSize($file->getSize());
        $media->setTempKey($autoSave ? null : $tempKey); 
        $media->setPosition($album->getMedia()->count());

        $this->em->persist($media);
        $this->em->flush(); // ID generieren

        // 3. Pfad-Konstruktion: uploads/{albumId}/{context?}/{mediaId}/
        $contextPart = ($context === 'default') ? '' : $context . '/';

        $relativeDir = sprintf(
            'uploads/%s/%s%s',
            $album->getId(),
            $contextPart,
            $media->getId()
        );

        $newFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) . '.' . $file->guessExtension();
        $finalUrl = $relativeDir . '/' . $newFilename;

        // 4. Physisches Speichern
        $absoluteTargetDir = $this->publicDir . '/' . $relativeDir;
        if (!is_dir($absoluteTargetDir)) {
            mkdir($absoluteTargetDir, 0775, true);
        }
        $file->move($absoluteTargetDir, $newFilename);

        // 5. DB-Update
        $media->setUrl($finalUrl);
        $this->em->flush();

        return $media;
    }
}
