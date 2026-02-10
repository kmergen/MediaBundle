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
use Symfony\Component\HttpFoundation\JsonResponse;

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
        $imageVariants = $request->request->all('imageVariants');
        $previewUrl = null; // Variable für die Vorschau

        foreach ($imageVariants as $variant) {
            // Wir merken uns die generierte URL des Thumbnails
            $generatedUrl = $imageService->thumb($media->getUrl(), $variant, true);

            // Wenn das die Variante für die Vorschau ist (z.B. die erste), nutzen wir sie
            if (!$previewUrl) {
                $previewUrl = $generatedUrl;
            }
        }

        // Fallback, falls keine Varianten definiert waren: Original-URL
        if (!$previewUrl) {
            $previewUrl = $media->getUrl();
        }

        return $this->json([
            'success' => true,
            'id'      => $media->getId(),
            'albumId' => $media->getAlbum()->getId(), // <--- NEU: ID zurückgeben!
            'url'     => '/' . ltrim($media->getUrl(), '/'),
            'previewUrl' => '/' . ltrim($previewUrl, '/'),
        ], 200);
    }


    #[Route('/media/reorder', name: 'kmergen_media_reorder', methods: ['POST'])]
    public function reorder(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['status' => 'ok']); // Nichts zu tun
        }

        $repo = $em->getRepository(Media::class);

        // Performance-Tipp: Iteriere über die IDs und update nur die Position
        foreach ($ids as $index => $id) {
            $media = $repo->find($id);
            if ($media) {
                // Position ist Index
                $media->setPosition($index);
            }
        }

        $em->flush();

        return $this->json(['status' => 'success']);
    }


    #[Route('/media/{id}', name: 'kmergen_media_delete', methods: ['DELETE'])]
    public function delete(
        Media $media,
        Request $request,
        MediaDeleteService $mediaDeleteService
    ): Response {
        // CSRF Check
        // 1. Token aus dem Header holen
        $token = $request->headers->get('X-CSRF-TOKEN');

        // 2. Prüfen gegen den generischen Namen 'media_delete' (muss mit Twig übereinstimmen)
        if (!$this->isCsrfTokenValid('media_delete', $token)) {
            return $this->json(['status' => 'error', 'message' => 'Invalid CSRF token'], 419);
        }
        // Löschen
        try {
            $mediaDeleteService->deleteMedia($media);
        } catch (\Exception $e) {
            return $this->json(['status' => 'error', 'message' => 'Delete failed'], 500);
        }

        return $this->json(['status' => 'success']);
    }
}

//  #[Route('/media/{id}/edit', name: 'kmergen_media_edit', methods: ['POST'])]
//     public function edit(
//         int $id,
//         Request $request,
//         MediaRepository $mediaRepository,
//         EntityManagerInterface $em
//     ): Response {
//         // Fetch the media entity using the repository
//         $media = $mediaRepository->find($id);

//         // Render the media details into a Twig template
//         return $this->render('@Media/edit.html.twig', [
//             'media' => $media,
//         ]);
    // }
