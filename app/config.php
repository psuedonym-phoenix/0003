<?php
// Database connection settings
// Centralised here so APIs and the admin UI share one source of truth.
$DB_HOST = 'cp53.domains.co.za';
$DB_NAME = 'filiades_eems';
$DB_USER = 'filiades_eemsdbuser';
$DB_PASS = 'hV&2w6JfW6@Pi3q1';

// Session settings
// Use a custom name to avoid collisions with other apps on the same host.
session_name('eems_admin');
