<?php

namespace Dashed\DashedOmnisocials\Exceptions;

use Illuminate\Http\Client\Response;

class OmnisocialsApiException extends \RuntimeException
{
    public function __construct(
        public readonly ?Response $response,
        public readonly ?string $errorCode,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $errorCode = $body['error']['code'] ?? null;
        $errorMessage = $body['error']['message'] ?? $response->body();

        return new self(
            response: $response,
            errorCode: $errorCode,
            message: "Omnisocials API error [{$response->status()}]: {$errorMessage}",
            code: $response->status(),
        );
    }
}
