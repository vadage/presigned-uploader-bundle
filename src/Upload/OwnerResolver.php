<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Upload;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The authenticated user, or the session for anonymous users.
 *
 * Note: anonymous uploads belong to the session id. If the session id changes between upload
 * and form submit (e.g. a login in between), the upload can no longer be claimed.
 */
final readonly class OwnerResolver implements OwnerResolverInterface
{
    private const SESSION_KEY = '_vadage_presigned_uploader';

    public function __construct(
        private RequestStack $requestStack,
        private ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function resolve(): string
    {
        if (null !== $user = $this->tokenStorage?->getToken()?->getUser()) {
            return 'user:'.$user->getUserIdentifier();
        }

        $session = $this->requestStack->getSession();
        // An empty session is not persisted (no cookie is sent), which would orphan the upload.
        if (!$session->has(self::SESSION_KEY)) {
            $session->set(self::SESSION_KEY, true);
        }

        // Owner ids are stored and passed to namers and listeners: never the session id itself.
        return 'session:'.hash('sha256', $session->getId());
    }
}
