<?php
/**
 * MCP v1 — placeholder (routing smoke test only).
 */
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

echo "thankhill MCP v1 placeholder — script reached.\n";
echo 'REQUEST_METHOD=' . ($_SERVER['REQUEST_METHOD'] ?? '') . "\n";
echo 'REQUEST_URI=' . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
