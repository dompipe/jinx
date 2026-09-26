<?php

declare(strict_types=1);

namespace jinx\oracle;

/** JINX-native web execution Oracle families. */
final class OracleJinxWebExecutionFamilies
{
    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        return [
            'jinx-island-server' => [
                'state' => 'executable',
                'owner' => OracleJinxIslandServerExecutor::class,
                'test' => 'scripts/test-oracle-jinx-island-server-execution.php',
                'ops' => [
                    'O_HTTP_ROUTE_TABLE',
                    'O_BACK_PAGE_API_BRIDGE',
                    'O_RESIDENT_ISLAND_STATE',
                    'O_WINDOW_INDEX_FRAME',
                    'O_IFRAME_ISLAND_RENDER',
                    'O_EVENTSOURCE_INVALIDATION',
                    'O_RESPONSE_ENVELOPE',
                ],
                'request_ops' => [
                    'http_route_table',
                    'back_page_api_bridge',
                    'resident_island_state',
                    'window_index_frame',
                    'iframe_island_render',
                    'eventsource_invalidation',
                    'response_envelope',
                ],
                'web_routes' => [
                    'GET /',
                    'GET /island',
                    'GET /events/island-state',
                    'GET /api/island-state',
                    'POST /api/island-state',
                    'GET /__health',
                ],
            ],
        ];
    }
}
