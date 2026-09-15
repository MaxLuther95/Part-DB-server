<?php

declare(strict_types=1);

namespace App\Services\Production;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** A confirmation applies only to the precise values and warnings the user saw. */
final readonly class IncompleteReleaseConfirmation
{
    public function __construct(private CsrfTokenManagerInterface $tokens) {}

    /** @param array<string, mixed> $state */
    public function tokenId(string $scope, array $state): string
    {
        return 'incomplete_release_'.$scope.'_'.hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function isAccepted(Request $request, string $tokenId): bool
    {
        return '1' === $request->request->get('_accept_incomplete')
            && $this->tokens->isTokenValid(new CsrfToken($tokenId, $request->request->getString('_incomplete_token')));
    }
}
