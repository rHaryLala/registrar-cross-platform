<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Erreur d'API au format RFC 9457 (application/problem+json).
 *
 * `code` est stable et lisible par la machine (ex. `auth.invalid_credentials`) :
 * c'est lui que le front utilise pour choisir le message et l'action, jamais le
 * texte français de `detail`.
 */
class ApiProblem extends Exception
{
    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        string $title,
        public readonly ?string $detail = null,
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($title);
    }

    public function report(): bool
    {
        return false; // erreur attendue, pas un incident
    }

    public function render(): JsonResponse
    {
        return self::response($this->status, $this->problemCode, $this->getMessage(), $this->detail, $this->extra, $this->headers);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string>  $headers
     */
    public static function response(int $status, string $code, string $title, ?string $detail = null, array $extra = [], array $headers = []): JsonResponse
    {
        return response()->json(
            array_merge([
                'type' => 'https://registrar.zurcher.edu.mg/problems/'.$code,
                'title' => $title,
                'status' => $status,
                'code' => $code,
                'detail' => $detail,
            ], $extra),
            $status,
            array_merge(['Content-Type' => 'application/problem+json'], $headers),
        );
    }
}
