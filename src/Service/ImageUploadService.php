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
        // 1. Parameter aus dem Request holen
        /** @var UploadedFile $file */
        $file = $request->files->get('file');

        // $targetMediaDir = $request->request->get('targetMediaDir');
        $ownerClass = $request->request->get('ownerClass'); // z.B. App\Entity\Room
        $ownerId    = $request->request->get('ownerId');
        $albumId = $request->request->get('albumId');
        $context = $request->request->get('context', 'default'); // NEU
        $tempKey = $request->request->get('tempKey') ?: null;

        // 2. Den Owner und das Album auflösen
        $owner = ($ownerClass && $ownerId) ? $this->em->getRepository($ownerClass)->find($ownerId) : null;

        $album = null;
        if ($albumId) {
            $album = $this->em->getRepository(MediaAlbum::class)->find($albumId);
        }

        // 2. Versuch: Über Owner (Neu-Anlage oder erste Zuweisung)
        if (!$album) {
            if ($owner instanceof MediaAlbumOwnerInterface) {
                $album = $owner->getMediaAlbum($context);

                if (!$album) {
                    $album = new MediaAlbum();
                    $owner->setMediaAlbum($album, $context);
                    $this->em->persist($album);
                }
            } else {
                throw new \LogicException(
                    $owner
                        ? sprintf('Entity "%s" muss das MediaAlbumOwnerInterface implementieren.', get_class($owner))
                        : 'Kein MediaAlbum gefunden und kein gültiger Owner zur Erstellung übergeben.'
                );
            }
        }
        // 2. Datei-Infos sammeln
        $fileSize = $file->getSize();
        $imageSize = getimagesize($file->getPathname());
        $fileDimension = $imageSize[0] . 'x' . $imageSize[1];
        $originalFilename = $file->getClientOriginalName();
        $newFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '.' . $file->guessExtension();

        // 3. Neue Media Entity erstellen
        $media = new Media();
        $media->setAlbum($album);
        $media->setName($originalFilename);
        $media->setMime($file->getClientMimeType());
        $media->setSize($fileSize);
        $media->setDimension($fileDimension);
        $media->setTempKey($tempKey);
        $media->setPosition($album->getMedia()->count() + 1);

        // 4. Persistieren
        $this->em->persist($media);
        $this->em->flush();

        // 5. Zentralisierte Verzeichnis & URL Generierung
        // Den Klassennamen holen wir uns sauber über Reflection (z.B. "room")
        // a. Basis-Ordner bestimmen (z.B. "room")
        $ownerFolder = strtolower((new \ReflectionClass($ownerClass))->getShortName());

        // b. ID oder TempKey bestimmen
        $ownerIdentifier = ($owner && $owner->getId()) ? $owner->getId() : 'tmp-' . $tempKey;

        // c. KONTEXT-LOGIK: 'default' weglassen
        $contextPart = ($context === 'default') ? '' : $context . '/';

        // d. Struktur zusammenbauen (sauber ohne doppelte Slashes)
        // uploads/room/15/101/bild.jpg (bei default)
        // uploads/room/15/docs/102/dokument.pdf (bei docs)
        $relativeDir = sprintf(
            'uploads/%s/%s/%s%s',
            $ownerFolder,
            $ownerIdentifier,
            $contextPart,
            $media->getId()
        );

        // Absoluter Pfad für das Filesystem
        $finalTargetDir = $this->publicDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        // Relativer Pfad für die Datenbank (URL)
        $media->setUrl($relativeDir . '/' . $newFilename);
        $this->em->flush();

        // 6. Datei physisch verschieben
        if (!is_dir($finalTargetDir)) {
            mkdir($finalTargetDir, 0775, true);
        }
        $file->move($finalTargetDir, $newFilename);
        return $media;
    }
}
