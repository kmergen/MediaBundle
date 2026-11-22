# Media Bundle for Symfony

This symfony bundle provides a behavior to work with media files in Symfony.
This bundle is compatible with Symfony versions equal or greater Symfony 7.2.

It currently handles:

- Hold all media files as Entities
- Image Upload
- Image Editing
- Image Deletion

## Install

MediaBundle requires PHP 8.4 or higher, Symfony 7.4 or higher and the PHP extension Imagick (`ext-imagick`), which
generates all image variants. Run the following command to install it in your application.

```bash
composer require kmergen/media-bundle
```

## Security

All media routes (`/media/upload`, `/media/reorder`, `/media/{id}/edit`, `/media/{id}`) require a
logged-in user and ask the security system for the attribute `MEDIA_ALBUM_MANAGE`
(`Kmergen\MediaBundle\Security\MediaAlbumVoter::MANAGE`). The subject is the affected
`MediaAlbum`, or `null` when an upload creates a new album.

Out of the box only users with `ROLE_ADMIN` may manage media. Change the role, or grant access to
more users with a voter of your own for the same attribute (with the default `affirmative`
strategy one granting voter is enough):

```yaml
# config/packages/media.yaml
media:
    manage_role: ROLE_ADMIN       # role that may manage every album
    upload:
        max_file_size: 10         # MB
        allowed_mime_types: [image/jpeg, image/png, image/webp]
```

```php
// Example: breeders may manage the album of their own profile
final class BreederAlbumVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return MediaAlbumVoter::MANAGE === $attribute && $subject instanceof MediaAlbum;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $breeder = $this->breeders->findOneBy(['images' => $subject]);

        return null !== $breeder && $breeder->getUser() === $token->getUser();
    }
}
```

Further protections:

- Upload, reorder and delete require the CSRF token `kmergen_media` in the `X-CSRF-TOKEN`
  header. The dashboard template renders it and the Stimulus controller sends it.
- Uploads must be real images of an allowed MIME type (detected from the content). The file name
  is slugged, the extension is derived from the MIME type and the `context` parameter may only
  contain `a-z`, `0-9`, `_` and `-`.
- Image variants are limited to the actions `resize`, `crop` and `compositeBlur` with sane sizes.

Also make sure your web server never executes or serves scripts from `public/uploads`, e.g. for
Apache in `public/.htaccess`:

```apache
RewriteRule ^uploads/(?!.+\.(?:jpe?g|png|webp)$) - [F,NC,L]
```

## Documentation

Read the [MediaBundle Docs](./docs/index.rst).

