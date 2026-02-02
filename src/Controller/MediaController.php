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
      'success' => true,
      'id'      => $media->getId(), // ID direkt oben
      'url'     => '/' . ltrim($media->getUrl(), '/'),
    ], 200);
  }

  #[Route('/media/image-list', name: 'kmergen_media_image_list', methods: ['GET', 'POST'])]
  public function imageList(
    Request $request,
    MediaRepository $mediaRepository,
    ImageService $imageService
  ): Response|string {
    // 1. Nur AJAX erlauben
    if (!$request->isXmlHttpRequest()) {
      throw new AccessDeniedHttpException();
    }

    // 2. JSON Body decodieren
    $content = $request->getContent();
    $data = !empty($content) ? json_decode($content, true) : [];

    // Falls JSON ungültig war, ist $data null -> Fallback auf leeres Array
    if (!is_array($data)) {
      $data = [];
    }

    // 3. Werte sicher aus dem Array holen
    // Wir nutzen NICHT mehr $request->get(), da die Daten im JSON Body liegen
    $albumId = $data['albumId'] ?? null;
    $mediaId = $data['mediaId'] ?? null;

    // Hier war dein Fehler: Wir lesen 'previewVariant' auch aus dem JSON (oder Default)
    $previewVariant = $data['previewVariant'] ?? 'crop,140,140,70';

    // 4. Validierung
    if ($albumId === null && $mediaId === null) {
      // Rückgabe eines sauberen JSON Fehlers statt 500er Exception (hilft beim Debuggen im Network Tab)
      return $this->json(['error' => 'Album ID or Media ID is missing.'], 400);
    }

    // 5. Bilder laden
    if ($mediaId !== null) {
      // Einzelbild (nach Upload)
      $image = $mediaRepository->find($mediaId);
      $images = $image ? [$image] : [];
    } else {
      // Alle Bilder des Albums
      $images = $mediaRepository->findBy(['album' => $albumId], ['position' => 'ASC']);
    }

    // 6. Thumbnails generieren
    foreach ($images as $image) {
      $image->previewUrl = $imageService->thumb($image->getUrl(), $previewVariant);
    }

    return $this->render('@Media/media/_image_list.html.twig', [
      'images' => $images,
    ]);
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
