<?php

declare(strict_types=1);

namespace Kmergen\MediaBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Kmergen\MediaBundle\Entity\Media;
use Kmergen\MediaBundle\Entity\MediaAlbum;
use Kmergen\MediaBundle\Exception\InvalidMediaUploadException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Stores an uploaded image below public/uploads/{albumId}/{context?}/{mediaId}/.
 *
 * Nothing the client sends ends up unchecked in the path: the context must be a simple slug, the
 * file name is slugged and the extension is derived from the detected MIME type. Only raster
 * images from the configured MIME whitelist are accepted.
 *
 * Permissions are not checked here; the controller asks MediaAlbumVoter before calling upload().
 */
class ImageUploadService
{
    private const string CONTEXT_PATTERN = '/^[a-z0-9_-]{1,32}$/';

    /** Detected MIME type => stored file extension. */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    private string $publicDir;

    /**
     * @param list<string> $allowedMimeTypes
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        KernelInterface $kernel,
        #[Autowire(param: 'kmergen_media.upload.max_file_size')]
        private readonly int $maxFileSizeMb = 10,
        #[Autowire(param: 'kmergen_media.upload.allowed_mime_types')]
        private readonly array $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'],
    ) {
        $this->publicDir = $kernel->getProjectDir() . '/public';
    }

    /**
     * Returns the album named by the "albumId" request field, or null when a new album is to be
     * created. Call this before upload() to check the permission for the album.
     *
     * @throws InvalidMediaUploadException for a malformed or unknown album id
     */
    public function findRequestedAlbum(Request $request): ?MediaAlbum
    {
        $albumId = $request->request->get('albumId');
        if (null === $albumId || '' === $albumId) {
            return null;
        }

        if (!\is_string($albumId) || !ctype_digit($albumId)) {
            throw new InvalidMediaUploadException('Invalid album id.');
        }

        return $this->em->getRepository(MediaAlbum::class)->find((int) $albumId)
            ?? throw new InvalidMediaUploadException('Unknown album.');
    }

    /**
     * @param MediaAlbum|null $album the album from findRequestedAlbum(); null creates a new one
     *
     * @throws InvalidMediaUploadException when the file or a parameter is not acceptable
     */
    public function upload(Request $request, ?MediaAlbum $album = null): Media
    {
        $file = $request->files->get('file');
        $context = $request->request->get('context', 'default');
        $tempKey = $request->request->get('tempKey');
        $autoSave = \in_array($request->request->get('autoSave'), ['1', 'true'], true);

        if (!\is_string($context) || 1 !== preg_match(self::CONTEXT_PATTERN, $context)) {
            throw new InvalidMediaUploadException('Invalid context.');
        }

        $extension = $this->validateImage($file);

        // 1. Album
        if (!$album) {
            $album = new MediaAlbum();
            $this->em->persist($album);
            $this->em->flush(); // generate the id
        }

        // 2. Media
        $media = new Media();
        $media->setAlbum($album);
        $media->setName(mb_substr($file->getClientOriginalName(), 0, 255));
        $media->setMime((string) $file->getMimeType());
        $media->setSize((int) $file->getSize());
        $media->setTempKey($autoSave || !\is_string($tempKey) || '' === $tempKey ? null : mb_substr($tempKey, 0, 255));
        $media->setPosition($album->getMedia()->count());

        $this->em->persist($media);
        $this->em->flush(); // generate the id

        // 3. Path: uploads/{albumId}/{context?}/{mediaId}/{slug}.{ext}
        $contextPart = ('default' === $context) ? '' : $context . '/';
        $relativeDir = sprintf('uploads/%d/%s%d', $album->getId(), $contextPart, $media->getId());
        $newFilename = $this->safeBaseName($file) . '.' . $extension;

        $absoluteTargetDir = $this->publicDir . '/' . $relativeDir;
        if (!Path::isBasePath($this->publicDir . '/uploads', $absoluteTargetDir)) {
            throw new InvalidMediaUploadException('Invalid target directory.');
        }

        // 4. Store the file
        if (!is_dir($absoluteTargetDir)) {
            mkdir($absoluteTargetDir, 0775, true);
        }
        $file->move($absoluteTargetDir, $newFilename);

        $size = getimagesize($absoluteTargetDir . '/' . $newFilename);
        if (false !== $size) {
            $media->setWidth($size[0]);
            $media->setHeight($size[1]);
        }

        // 5. Save the URL
        $media->setUrl($relativeDir . '/' . $newFilename);
        $this->em->flush();

        return $media;
    }

    /**
     * @return string the file extension for the detected image type
     */
    private function validateImage(mixed $file): string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new InvalidMediaUploadException('No valid file uploaded.');
        }

        if ($file->getSize() > $this->maxFileSizeMb * 1024 * 1024) {
            throw new InvalidMediaUploadException('The file is too large.');
        }

        // Detected from the file content, never taken from the client.
        $mimeType = $file->getMimeType();
        if (null === $mimeType || !\in_array($mimeType, $this->allowedMimeTypes, true) || !isset(self::EXTENSIONS[$mimeType])) {
            throw new InvalidMediaUploadException('This file type is not allowed.');
        }

        if (false === @getimagesize($file->getPathname())) {
            throw new InvalidMediaUploadException('The file is not a readable image.');
        }

        return self::EXTENSIONS[$mimeType];
    }

    private function safeBaseName(UploadedFile $file): string
    {
        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slug = (new AsciiSlugger())->slug($name)->lower()->truncate(80)->toString();

        return '' !== $slug ? $slug : 'image';
    }
}
