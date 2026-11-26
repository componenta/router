<?php

declare(strict_types=1);

use Componenta\Http\Router\Benchmarks\RouteLifecycleFixture;
use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\CompiledRoutes;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;

require dirname(__DIR__) . '/vendor/autoload.php';

// One invocation is one process; prepare outside the timed process.
[$script, $mode, $count, $layout, $file] = array_pad($argv, 5, null);
if (!in_array($mode, ['prepare', 'source', 'cached', 'opcache', 'parity'], true)
    || !in_array($count, ['100', '1000', '10000'], true)
    || !in_array($layout, ['wide', 'shared', 'broad'], true) || $file === null) {
    fwrite(STDERR, "Usage: php tools/route-lifecycle.php prepare|source|cached|opcache|parity 100|1000|10000 wide|shared|broad /path/to/cache.php\n");
    exit(2);
}
$count = (int) $count;
$last = intdiv($count, 2) - 1;
$uri = RouteLifecycleFixture::uri($last, $layout);

if ($mode === 'prepare') {
    (new RouteCacheGenerator())->generate(RouteLifecycleFixture::routes($count, $layout), $file);
    echo json_encode(['bytes' => filesize($file)], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
if ($mode === 'parity') {
    $source = RouteLifecycleFixture::routes($count, $layout);
    $cached = CompiledRoutes::fromCache($file);
    $observe = static function ($routes, string $path, string $method): array {
        try {
            $match = $routes->match($routes, $path, $method);
            return [$match->name, $match->parameters, $match->route->toArray(), $match->middlewares?->toArray()];
        } catch (MethodNotAllowedException $e) { return [$e::class, $e->getCode(), $e->allowedMethods]; }
        catch (RouteNotFoundException $e) { return [$e::class, $e->getCode()]; }
    };
    $checks = 0;
    for ($i = 0; $i <= $last; ++$i) {
        $dynamic = RouteLifecycleFixture::uri($i, $layout);
        foreach ([[$dynamic, 'GET'], [$dynamic, 'POST'], [$dynamic, 'DELETE'], ['/static/' . $i, 'GET'], ['/static/' . $i, 'POST']] as [$path, $method]) {
            if ($observe($source, $path, $method) !== $observe($cached, $path, $method)) {
                throw new RuntimeException('Observation mismatch: ' . $method . ' ' . $path);
            }
            ++$checks;
        }
        if ($source->generate($source, 'd' . $i, ['id' => 42]) !== $cached->generate($cached, 'd' . $i, ['id' => 42])) {
            throw new RuntimeException('Generation mismatch: d' . $i);
        }
        ++$checks;
    }
    echo json_encode(['checks' => $checks], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit;
}
if ($mode === 'opcache') {
    if (!function_exists('opcache_compile_file') || !opcache_compile_file($file) || !opcache_is_script_cached($file)) {
        throw new RuntimeException('OPcache is not enabled or the map could not be cached.');
    }
}

$start = hrtime(true);
$routes = $mode === 'source' ? RouteLifecycleFixture::routes($count, $layout) : CompiledRoutes::fromCache($file);
$loaded = hrtime(true);
$match = $routes->match($routes, $uri, 'GET');
$finished = hrtime(true);
if ($match->name !== 'd' . $last || $match->parameters !== ['scope' => 'read', 'id' => 42]) {
    throw new RuntimeException('Incorrect route benchmark result.');
}
echo json_encode([
    'php' => PHP_VERSION,
    'pcre' => PCRE_VERSION,
    'mode' => $mode,
    'count' => $count,
    'layout' => $layout,
    'opcache_cached' => function_exists('opcache_is_script_cached') && opcache_is_script_cached($file),
    'init_us' => ($loaded - $start) / 1000,
    'first_match_us' => ($finished - $loaded) / 1000,
    'total_us' => ($finished - $start) / 1000,
    'peak_bytes' => memory_get_peak_usage(false),
], JSON_THROW_ON_ERROR) . PHP_EOL;
