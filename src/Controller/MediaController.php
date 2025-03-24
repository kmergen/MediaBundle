<?php

namespace Kmergen\MediaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Kmergen\MediaBundle\Entity\Media;
use Kmergen\MediaBundle\Repository\MediaRepository;
use Kmergen\MediaBundle\Service\ImageService;
use Kmergen\MediaBundle\Service\ImageUploadService;
use Kmergen\MediaBundle\Service\MediaDeleteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

class MediaController extends AbstractController
{
  private string $publicDir;

  public function __construct(private readonly KernelInterface $kernel, private readonly ParameterBagInterface $params)
  {
    $this->publicDir = $kernel->getProjectDir() . '/public';
  }

  #[Route('/media/upload', name: 'kmergen_media_upload', methods: ['POST', 'GET'])]
  public function upload(
    Request $request,
    ImageUploadService $imageUploadService,
    ImageService $imageService
  ): Response {
    // Handle the file upload and save the media
    $media = $imageUploadService->upload($request);

    // Handle image variants if specified
    $imageVariants = $request->request->all('image_variants');
    foreach ($imageVariants as $variant) {
      $imageService->thumb($media->getUrl(), $variant, true);
    }

    return $this->json([
      'status' => 'success',
      'media' => [
        'id' => $media->getId(),
        'url' => $media->getUrl()
      ]
    ], 200);
  }

  #[Route('/media/image-list', name: 'kmergen_media_image_list', methods: ['GET', 'POST'])]
  public function imageList(
    Request $request,
    MediaRepository $mediaRepository,
    ImageService $imageService
  ): Response|string {
    if (!$request->isXmlHttpRequest()) {
      throw new AccessDeniedHttpException();
    }
    // Decode the JSON content from the request
    $data = json_decode($request->getContent(), true);

    // Now retrieve entityName and entityId from the decoded data
    $entityName = $data['entityName'] ?? null;
    $entityId = $data['entityId'] ?? null;
    $mediaId = $data['mediaId'] ?? null;  // optional mediaId
    $previewVariant = $request->get('previewVariant', 'crop,140,140,70');

    if ($entityName === null || $entityId === null) {
      // Handle the case where the expected data is not provided
      throw new \InvalidArgumentException('Entity name or ID is missing.');
    }

    if ($mediaId !== null) {
      // If a specific mediaId is provided, fetch only that image
      $images = [$mediaRepository->find($mediaId)];
    } else {
      // Otherwise, fetch all images for the entity
      $images = $mediaRepository->findBy([
        'entityName' => $entityName,
        'entityId' => $entityId,
      ], ['position' => 'ASC']);
    }

    // Generate preview variant URLs using ImageService
    foreach ($images as $image) {
      $image->previewUrl = $imageService->thumb($image->getUrl(), $previewVariant);
    }

    return $this->render('@Media/media/_image_list.html.twig', [
      'images' => $images,
    ]);
  }

  #[Route('/media/update-positions', name: 'kmergen_media_position_update', methods: ['POST'])]
  public function updatePositions(
    Request $request,
    MediaRepository $mediaRepository,
  ): Response|string {
    $positions = json_decode($request->getContent(), true)['positions'];

    try {
      $mediaRepository->updateMediaPositions($positions);
      return $this->json(['status' => 'success']);
    } catch (Exception $e) {
      return $this->json(['status' => 'error', 'message' => $e->getMessage()], 400);
    }
  }

  #[Route('/media/{id}/edit', name: 'kmergen_media_edit', methods: ['POST'])]
  public function edit(
    int $id,
    Request $request,
    MediaRepository $mediaRepository,
    EntityManagerInterface $em
  ): Response {
    // Fetch the media entity using the repository
    $media = $mediaRepository->find($id);

    // Render the media details into a Twig template
    return $this->render('@Media/media/edit.html.twig', [
      'media' => $media,
    ]);
  }

  #[Route('/media/test', name: 'kmergen_media_test', methods: ['GET'])]
  public function test(
    Request $request,
    MediaRepository $mediaRepository,
    EntityManagerInterface $em
  ): Response {
    // Access the parameter
    $phrase = $this->params->get('media.phrase');
    // Render the media details into a Twig template
    return $this->render('@Media/test1.html.twig', [
      'phrase' => $phrase,
    ]);
  }

  #[Route('/media/{id}/delete', name: 'kmergen_media_delete', methods: ['POST'])]
  public function delete(
    Media $media,
    Request $request,
    EntityManagerInterface $em,
    MediaDeleteService $mediaDeleteService
  ): Response {
    // Remove the media entity from the database
    if ($this->isCsrfTokenValid('delete' . $media->getId(), $request->getPayload()->get('_token'))) {
      $mediaDeleteService->deleteMedia($media);
    }
    // Return a JSON response or redirect, depending on your needs
    return $this->json(['status' => 'success', 'message' => 'Media deleted successfully.']);
  }
}
