<?php
/**
 * MCP Streamable HTTP endpoint — POST JSON-RPC (GET returns 405 until SSE is implemented).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/mcp_streamable_http.php';

mcp_v1_main();
