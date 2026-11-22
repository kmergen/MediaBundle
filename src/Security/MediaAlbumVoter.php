<?php

declare(strict_types=1);

namespace Kmergen\MediaBundle\Security;

use Kmergen\MediaBundle\Entity\MediaAlbum;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Default permission check for all media routes (upload, reorder, edit, delete).
 *
 * The subject is the affected album, or null when an upload creates a new album. Out of the box only
 * users with the configured role (kmergen_media.manage_role, default ROLE_ADMIN) may manage media.
 * Applications grant more, e.g. owners of an album, with their own voter for the same attribute:
 * with the default "affirmative" strategy one granting voter is enough.
 *
 * @extends Voter<string, MediaAlbum|null>
 */
final class MediaAlbumVoter extends Voter
{
    public const string MANAGE = 'MEDIA_ALBUM_MANAGE';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        #[Autowire(param: 'kmergen_media.manage_role')]
        private readonly string $manageRole,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && (null === $subject || $subject instanceof MediaAlbum);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return $this->accessDecisionManager->decide($token, [$this->manageRole]);
    }
}
