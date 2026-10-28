<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Throwable;

class GeminiException extends Exception
{
    public const NOT_CONFIGURED = 'gemini_not_configured';

    public const REQUEST_FAILED = 'gemini_request_failed';

    public const INVALID_RESPONSE = 'gemini_invalid_response';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 502,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(
            self::NOT_CONFIGURED,
            'The Gemini API key is not configured. Set GEMINI_API_KEY in your .env file.',
            503
        );
    }

    public static function requestFailed(string $detail, ?Throwable $previous = null): self
    {
        return new self(
            self::REQUEST_FAILED,
            'Gemini could not process the request: '.$detail,
            502,
            $previous
        );
    }

    public static function invalidResponse(string $detail): self
    {
        return new self(
            self::INVALID_RESPONSE,
            'Gemini returned a response that could not be read: '.$detail,
            502
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'invoices.ai.errors.'.$this->errorCode,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
