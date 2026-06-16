<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

/**
 * Configuration keys for the router library.
 */
final class ConfigKey extends \Componenta\Config\ConfigKey
{
    /** Path to the routes definition file. */
    public const string ROUTES_FILE = 'Componenta\Http\Router::routes_file';

    /**
     * Optional explicit path to the compiled routes cache file. When
     * absent, the cache path is derived from {@see ROUTES_FILE} by
     * inserting `.cache` before the extension (legacy behaviour, kept
     * for backward compatibility).
     */
    public const string ROUTES_CACHE_FILE = 'Componenta\Http\Router::routes_cache_file';

    /**
     * Whether DispatchRouteMiddlewareFactory may return a memoizing
     * dispatcher for resolved route middleware.
     */
    public const string CACHE_RESOLVED_ROUTE_MIDDLEWARE = 'Componenta\Http\Router::cache_resolved_route_middleware';

    /**
     * Master switch for compiled router fast paths. When disabled,
     * production falls back to the plain routes file and route middleware
     * pipelines are resolved per request.
     */
    public const string COMPILED_PIPELINE = 'Componenta\Http\Router::compiled_pipeline';
}
