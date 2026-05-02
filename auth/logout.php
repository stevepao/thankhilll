<?php
/**
 * Logs out current session and redirects to login.
 */
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

auth_logout_and_redirect();
