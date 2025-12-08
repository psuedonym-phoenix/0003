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
     * Validate the supplied API key and halt with a 403 response when missing or invalid.
     */
    function enforce_api_key(array $payload): void
    {
        if (!isset($payload['api_key']) || $payload['api_key'] !== EEMS_API_KEY) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Forbidden']);
            exit;
        }
    }
}
