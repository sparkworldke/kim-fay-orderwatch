<?php

namespace App\Exceptions;

use Exception;

class AiGenerationException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $codeKey = 'AI_PROVIDER_ERROR',
        public readonly int $httpStatus = 502,
        public readonly ?string $provider = null,
    ) {
        parent::__construct($message);
    }

    /** @return array{message: string, code: string, provider: string|null} */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->codeKey,
            'provider' => $this->provider,
        ];
    }
}
