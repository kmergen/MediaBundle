<?php

declare(strict_types=1);

namespace Kmergen\MediaBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
use Kmergen\MediaBundle\Exception\InvalidMediaUploadException;
use Kmergen\MediaBundle\Security\MediaAlbumVoter;
use Kmergen\MediaBundle\Form\MediaEditType;
use Kmergen\MediaBundle\Service\ImageService;
use Kmergen\MediaBundle\Service\ImageUploadService;
use Kmergen\MediaBundle\Service\MediaDeleteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Endpoints of the media dashboard. Every route requires a login and asks MediaAlbumVoter::MANAGE
 * for the affected album; upload, reorder and delete also require the CSRF token CSRF_TOKEN_ID
 * in the X-CSRF-TOKEN header (the dashboard template renders it).
 */
class MediaController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'kmergen_media';

    private const array VARIANT_ACTIONS = ['resize', 'crop', 'compositeBlur'];
    private const int MAX_VARIANTS = 5;
    private const int MAX_VARIANT_EDGE = 4000;
    private const int MAX_REORDER_IDS = 500;

    #[Route('/media/upload', name: 'kmergen_media_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        ImageUploadService $imageUploadService,
        ImageService $imageService
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        $this->denyUnlessCsrfHeaderValid($request);

        try {
            $album = $imageUploadService->findRequestedAlbum($request);
            $this->denyAccessUnlessGranted(MediaAlbumVoter::MANAGE, $album);

            // Validate the variants before anything is stored
            $imageVariants = $this->parseImageVariants($request->request->get('imageVariants'));

            $media = $imageUploadService->upload($request, $album);
        } catch (InvalidMediaUploadException $e) {
            return $this->json(['status' => 'error', 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        $previewUrl = null;

        // Loop through the purely structured arrays
        foreach ($imageVariants as $variant) {
            // Extract core arguments with smart defaults
            $action  = $variant['action'] ?? 'resize';
            $width   = !empty($variant['width']) ? (int)$variant['width'] : null;
            $height  = !empty($variant['height']) ? (int)$variant['height'] : null;
            $quality = isset($variant['quality']) ? (int)$variant['quality'] : 80;

            // Extract any future named arguments (like blur_radius, watermark, etc.)
            $options = $variant;
            unset($options['action'], $options['width'], $options['height'], $options['quality']);

            // Generate thumbnail using the new signature
            $generatedUrl = $imageService->thumb(
                $media->getUrl(),
                $action,
                $width,
                $height,
                $quality,
                true, // force regeneration on upload
                ...$options // unpack future options
            );

            // Use the first generated variant as the preview
            if ($generatedUrl && !$previewUrl) {
                $previewUrl = $generatedUrl;
            }
        }

        // Fallback, falls keine Varianten definiert waren: Original-URL
        if (!$previewUrl) {
            $previewUrl = $media->getUrl();
        }

        return $this->json([
            'success'    => true,
            'id'         => $media->getId(),
            'albumId'    => $media->getAlbum()->getId(),
            'url'        => '/' . ltrim($media->getUrl(), '/'),
            'previewUrl' => '/' . ltrim($previewUrl, '/'),
        ], 200);
    }


    #[Route('/media/reorder', name: 'kmergen_media_reorder', methods: ['POST'])]
    public function reorder(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        $this->denyUnlessCsrfHeaderValid($request);

        $data = json_decode($request->getContent(), true);
        $ids = \is_array($data) ? ($data['ids'] ?? []) : null;

        if (!\is_array($ids) || \count($ids) > self::MAX_REORDER_IDS) {
            throw new BadRequestHttpException('Invalid media ids.');
        }

        if ([] === $ids) {
            return $this->json(['status' => 'ok']); // nothing to do
        }

        $ids = array_values($ids);
        foreach ($ids as $id) {
            if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
                throw new BadRequestHttpException('Invalid media id.');
            }
        }

        $mediaById = [];
        foreach ($em->getRepository(Media::class)->findBy(['id' => $ids]) as $media) {
            $mediaById[$media->getId()] = $media;
        }

        // All media must exist and belong to one album that the user may manage
        $albums = [];
        foreach ($ids as $id) {
            $media = $mediaById[(int) $id] ?? throw $this->createNotFoundException('Unknown media.');
            $album = $media->getAlbum() ?? throw $this->createAccessDeniedException('Media without album.');
            $albums[$album->getId()] = $album;
        }

        if (1 !== \count($albums)) {
            throw new BadRequestHttpException('Media from different albums.');
        }

        $this->denyAccessUnlessGranted(MediaAlbumVoter::MANAGE, reset($albums));

        foreach ($ids as $index => $id) {
            $mediaById[(int) $id]->setPosition($index);
        }

        $em->flush();

        return $this->json(['status' => 'success']);
    }


    #[Route('/media/{id}', name: 'kmergen_media_delete', methods: ['DELETE'])]
    public function delete(
        Media $media,
        Request $request,
        MediaDeleteService $mediaDeleteService,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        $this->denyUnlessCsrfHeaderValid($request);
        $this->denyAccessUnlessGranted(MediaAlbumVoter::MANAGE, $media->getAlbum());

        try {
            $mediaDeleteService->deleteMedia($media);
        } catch (\Exception $e) {
            return $this->json(['status' => 'error', 'message' => 'Delete failed'], 500);
        }

        return $this->json(['status' => 'success']);
    }

    #[Route('/media/{id}/edit', name: 'media_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Media $media,
        EntityManagerInterface $em,
        ImageService $imageService
    ): Response {
        // The form itself is protected by the regular form CSRF token
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');
        $this->denyAccessUnlessGranted(MediaAlbumVoter::MANAGE, $media->getAlbum());

        // 1. Locales auslesen
        $localesParam = $request->query->get('locales');

        if ($localesParam) {
            $editableLocales = explode(',', $localesParam);
        } else {
            $editableLocales = [$request->getLocale()];
        }

        // 2. Formular erstellen
        $form = $this->createForm(MediaEditType::class, $media, [
            // --- HIER IST DER FIX ---
            // Wir müssen die 'locales' auch in die Action-URL schreiben,
            // damit sie beim POST-Request wieder da sind!
            'action' => $this->generateUrl('media_edit', [
                'id' => $media->getId(),
                'locales' => $localesParam // <--- Das fehlte!
            ]),

            'current_locale' => $request->getLocale(),
            'locales' => $editableLocales,
        ]);

        $form->handleRequest($request);

        // --- POST REQUEST (Speichern) ---
        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            return $this->json([
                'status' => 'success',
                'id' => $media->getId(),
                // Wir geben den Text für die aktuelle Sprache zurück für die Vorschau
                'alt' => $media->getAlt($request->getLocale()),
            ]);
        }

        // --- Thumbnail Logik für das Modal ---
        // Fallback auf Original-URL, falls Thumb-Generierung fehlschlägt
        $previewUrl = $media->getUrl();
        try {
            $thumbUrl = $imageService->thumb($media->getUrl(), 'resize', 800, 600);
            if ($thumbUrl) {
                $previewUrl = $thumbUrl;
            }
        } catch (\Exception $e) {
            // Logging könnte hier sinnvoll sein, aber Fallback reicht für UI
        }

        // --- Fehlerhafte Validierung (Status 422) ---
        $response = null;
        if ($form->isSubmitted() && !$form->isValid()) {
            $response = new Response();
            $response->setStatusCode(422);
        }

        // --- HTML Rendern ---
        return $this->render('@Media/_edit_form.html.twig', [
            'form' => $form,
            'media' => $media,
            'previewUrl' => $previewUrl,
            // WICHTIG: Locales an Template übergeben für die Schleife
            'locales' => $editableLocales
        ], $response);
    }

    private function denyUnlessCsrfHeaderValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Parses the JSON list of image variants sent by the dashboard and rejects anything that is
     * not a known action with sane dimensions.
     *
     * @return list<array<string, mixed>>
     */
    private function parseImageVariants(mixed $json): array
    {
        if (null === $json || '' === $json) {
            return [];
        }

        $variants = \is_string($json) ? json_decode($json, true) : null;
        if (!\is_array($variants) || !array_is_list($variants) || \count($variants) > self::MAX_VARIANTS) {
            throw new InvalidMediaUploadException('Invalid image variants.');
        }

        foreach ($variants as $variant) {
            if (!\is_array($variant) || !\in_array($variant['action'] ?? 'resize', self::VARIANT_ACTIONS, true)) {
                throw new InvalidMediaUploadException('Invalid image variant.');
            }

            foreach (['width', 'height'] as $edge) {
                $value = $variant[$edge] ?? null;
                if (null !== $value && (!\is_int($value) || $value < 1 || $value > self::MAX_VARIANT_EDGE)) {
                    throw new InvalidMediaUploadException('Invalid image variant size.');
                }
            }

            $quality = $variant['quality'] ?? 80;
            if (!\is_int($quality) || $quality < 1 || $quality > 100) {
                throw new InvalidMediaUploadException('Invalid image variant quality.');
            }

            foreach ($variant as $key => $value) {
                // Extra options are passed to ImageService::thumb() as named arguments
                if (!\is_string($key) || 1 !== preg_match('/^[a-z][a-z0-9_]{0,31}$/i', $key)
                    || \in_array($key, ['imgRef', 'force'], true) || !\is_scalar($value)) {
                    throw new InvalidMediaUploadException('Invalid image variant option.');
                }
            }
        }

        return $variants;
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

}
