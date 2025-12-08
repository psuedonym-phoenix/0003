<?php
// Shared bootstrap for API endpoints.
// Loads configuration, database helper, and API key from the app folder so
// credentials are centralised and not repeated inside each API script.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/api_key.php';

// Ensure the helper is only declared once if multiple APIs include this file.
if (!function_exists('enforce_api_key')) {
    /**
     * Extract the API key from common sources so legacy Excel callers that send
     * the key under slightly different names still validate correctly.
     */
    function resolve_api_key(array $payload): ?string
    {
        // Accept the canonical field, common camelCase variant, a query string
        // fallback, or the X-API-Key header. The first non-empty value wins.
        $candidates = [
            $payload['api_key'] ?? null,
            $payload['apiKey'] ?? null,
            $_GET['api_key'] ?? null,
            $_SERVER['HTTP_X_API_KEY'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Validate the supplied API key and halt with a 403 response when missing or invalid.
     */
    function enforce_api_key(array $payload): void
    {
        $apiKey = resolve_api_key($payload);

        if ($apiKey !== EEMS_API_KEY) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
    }
}
