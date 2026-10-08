<?php

declare(strict_types=1);

namespace Vadage\PresignedUploaderBundle\Tests\App;

use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Evaluates expressions with the subject only, like the SecurityBundle's expression voter without a token.
 */
final class ExpressionAuthorizationChecker implements AuthorizationCheckerInterface
{
    public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
    {
        return $attribute instanceof Expression && true === (new ExpressionLanguage())->evaluate($attribute, ['subject' => $subject]);
    }
}
