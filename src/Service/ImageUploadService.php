<?php

// src/Service/ImageUploadService.php

namespace Kmergen\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
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
    // Extract necessary parameters from the request
    /** @var UploadedFile $file */
    $file = $request->files->get('file');
    $targetDir = $request->request->get('targetDir');
    $entityId = $request->request->get('entityId');
    if (!$entityId) {
      $entityId = null;
    }
    $entityName = $request->request->get('entityName');
    $tempKey = $request->request->get('tempKey');
    if (!$tempKey) {
      $tempKey = null;
    }
    $fileSize = $file->getSize();
    $imageSize = getimagesize($file->getPathname());
    $fileDimension = $imageSize[0] . 'x' . $imageSize[1];
    $originalFilename = $file->getClientOriginalName();
    $newFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . '_' . uniqid() . '.' . $file->guessExtension();

    // Find the highest current position for this entity
    $highestPosition = $this->findHighestPosition($entityId, $entityName);

    // Create a new Media entity
    $media = new Media();
    $media->setName($originalFilename);
    $media->setMime($file->getClientMimeType());
    $media->setSize($fileSize);
    $media->setDimension($fileDimension);
    $media->setEntityId($entityId);
    $media->setEntityName($entityName);
    $media->setPosition($highestPosition + 1);
    if ($entityId === null && $tempKey !== null) {
      $media->setTempKey($tempKey);
    }

    // Persist the Media entity
    $this->em->persist($media);
    $this->em->flush();

    // Determine the target directory
    $targetDir = $this->publicDir . DIRECTORY_SEPARATOR . $targetDir . DIRECTORY_SEPARATOR . $media->getId();
    $media->setUrl($request->get('targetDir') . '/' . $media->getId() . '/' . $newFilename);
    $this->em->flush();
    // Handle the uploaded file

    $file->move($targetDir, $newFilename);

    return $media;
  }

  private function findHighestPosition(?int $entityId, ?string $entityName): int
  {
    if ($entityId === null) {
      return 0;
    }
    $result = $this->em->createQueryBuilder()
      ->select('MAX(m.position)')
      ->from(Media::class, 'm')
      ->where('m.entityId = :entityId')
      ->andWhere('m.entityName = :entityName')
      ->setParameter('entityId', $entityId)
      ->setParameter('entityName', $entityName)
      ->getQuery()
      ->getSingleScalarResult();

    return $result !== null ? (int)$result : 0;
  }
}
