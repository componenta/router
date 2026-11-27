<?php

declare(strict_types=1);

use Componenta\Http\Router\Cache\RouteCacheGenerator;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Routes;
use Componenta\VarExport\Exception\ExceptionInterface;

it('does not replace a standalone map with a closure of different lifetime', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'route_stateful_');
    if ($file === false) { throw new RuntimeException('Cannot create fixture.'); }
    try {
        file_put_contents($file, 'previous map');
        $routes = new Routes();
        $count = 0;
        $routes->addRoute(RouteRecord::get('counter', '/counter', static function () use (&$count): int { return ++$count; }));
        expect(fn () => (new RouteCacheGenerator())->generate($routes, $file))->toThrow(ExceptionInterface::class);
        expect(file_get_contents($file))->toBe('previous map');
    } finally { unlink($file); }
});


it('keeps filesystem failures and cleanup inside the standalone cache writer contract', function (string $case, bool $throwWarnings): void {
    $root = sys_get_temp_dir() . '/router_cache_io_' . bin2hex(random_bytes(8));
    mkdir($root);
    mkdir($root . '/directory');
    file_put_contents($root . '/old.php', 'previous map');
    $file = match ($case) {
        'directory' => $root . '/directory',
        'parent-file' => $root . '/old.php/child/cache.php',
        'invalid-name' => $root . "/bad\0.php",
    };
    $warnings = 0;
    $handler = static function (int $severity, string $message) use (&$warnings, $throwWarnings): bool {
        ++$warnings;
        if ($throwWarnings) { throw new ErrorException($message, 0, $severity); }
        return true;
    };
    $routes = (new Routes())->addRoute(RouteRecord::get('home', '/', 'Handler'));
    $generator = new RouteCacheGenerator();

    try {
        set_error_handler($handler);
        try {
            try {
                $generator->generate($routes, $file);
                throw new LogicException('Expected cache publication to fail.');
            } catch (RuntimeException $error) {
                expect($error->getPrevious())->toBeInstanceOf($case === 'invalid-name' ? ValueError::class : ErrorException::class);
            }
            expect($warnings)->toBe(0)
                ->and(file_get_contents($root . '/old.php'))->toBe('previous map')
                ->and(is_dir($root . '/directory'))->toBeTrue()
                ->and(glob($root . '/*.tmp.*'))->toBe([]);

            $current = set_error_handler(static fn () => true);
            restore_error_handler();
            expect($current)->toBe($handler);

            // A failed publication must not prevent a subsequent valid build.
            $generator->generate($routes, $root . '/old.php');
            expect(\Componenta\Http\Router\CompiledRoutes::fromCache($root . '/old.php')->has('home'))->toBeTrue()
                ->and($warnings)->toBe(0);
            $current = set_error_handler(static fn () => true);
            restore_error_handler();
            expect($current)->toBe($handler);
        } finally {
            restore_error_handler();
        }
    } finally {
        foreach (glob($root . '/*.tmp.*') as $temporary) { unlink($temporary); }
        unlink($root . '/old.php');
        rmdir($root . '/directory');
        rmdir($root);
    }
})->with(['directory', 'parent-file', 'invalid-name'])->with([false, true]);
