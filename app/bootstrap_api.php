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
        // fallback, or the X-API-Key/Authorization header. The first non-empty
        // value wins. Trimming guards against accidental spaces pasted into Excel.
        $candidates = [
            $payload['api_key'] ?? null,
            $payload['apiKey'] ?? null,
            $payload['API_KEY'] ?? null,
            $payload['apikey'] ?? null,
            $_POST['api_key'] ?? null,
            $_POST['apiKey'] ?? null,
            $_GET['api_key'] ?? null,
            $_GET['apiKey'] ?? null,
            $_SERVER['HTTP_X_API_KEY'] ?? null,
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            // Accept either a bare key or prefixed header value such as
            // "Bearer <key>" or "ApiKey <key>"; Excel callers often use plain values.
            $candidate = trim((string) $candidate);
            if (stripos($candidate, 'bearer ') === 0 || stripos($candidate, 'apikey ') === 0) {
                $candidate = trim(substr($candidate, strpos($candidate, ' ') + 1));
            }

            if ($candidate !== '') {
                return $candidate;
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
            echo json_encode([
                'success' => false,
                'error' => 'Forbidden: missing or invalid API key',
            ]);
            exit;
        }
    }
}
