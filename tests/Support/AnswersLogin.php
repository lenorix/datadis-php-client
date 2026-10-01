<?php

declare(strict_types=1);

namespace Lenorix\DatadisClient\Tests\Support;

use Lenorix\DatadisClient\Http\RequestFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Answers the login with a valid token itself and passes every other request on, for tests about
 * what comes after logging in (the public API needs the token too).
 */
final readonly class AnswersLogin implements ClientInterface
{
    public function __construct(private ClientInterface $inner) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (str_ends_with($request->getUri()->getPath(), RequestFactory::LOGIN_PATH)) {
            return Responses::text(Tokens::datadis(time()));
        }

        return $this->inner->sendRequest($request);
    }
}
