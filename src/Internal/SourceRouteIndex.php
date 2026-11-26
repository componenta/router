<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteConflictException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\RouteCompileResult;
use Componenta\Http\Router\RouteRecord;

/** @internal Immutable source route index. Construction never modifies the collection. */
final readonly class SourceRouteIndex
{
    /** @var array<string, array<string, RouteRecord>> */
    private array $staticRoutes;
    /** @var array<string, list<array{route: RouteRecord, regex: string, compiled: RouteCompileResult}>> */
    private array $dynamicRoutes;
    /** @var array<string, RouteCompileResult> */
    private array $patterns;

    /** @param iterable<RouteRecord> $records */
    public function __construct(iterable $records, CompilerInterface $compiler)
    {
        $static = $dynamic = $patterns = [];
        foreach ($records as $route) {
            $compiled = $compiler->compile($route->path, $route->tokens, $route->defaults);
            $patterns[$route->name] = $compiled;
            $regex = RouteRegex::anchored($compiled->regex);
            if ($compiled->hasParameters()) {
                RouteRegex::validate($regex);
            }
            foreach ($route->methods as $method) {
                if ($compiled->hasParameters()) {
                    $dynamic[$method][] = ['route' => $route, 'regex' => $regex, 'compiled' => $compiled];
                    continue;
                }
                $previous = $static[$method][$route->path] ?? null;
                if ($previous !== null) {
                    throw new RouteConflictException($method, $route->path, $previous->name, $route->name);
                }
                $static[$method][$route->path] = $route;
            }
        }
        $this->staticRoutes = $static;
        $this->dynamicRoutes = $dynamic;
        $this->patterns = $patterns;
    }

    public function pattern(string $name): RouteCompileResult
    {
        return $this->patterns[$name];
    }

    public function match(string $uri, string $method): MatchResult
    {
        $method = strtoupper($method);
        $uri = '/' . ltrim($uri, '/');

        $static = $this->staticRoutes[$method][$uri] ?? null;
        if ($static !== null) {
            return self::result($static, $this->patterns[$static->name]->defaults);
        }

        foreach ($this->dynamicRoutes[$method] ?? [] as $entry) {
            if (RouteRegex::match($entry['regex'], $uri, $matches)) {
                $pattern = $entry['compiled'];
                return self::result($entry['route'], RouteParameters::extract($matches, $pattern->parameterNames(), $pattern->defaults));
            }
        }

        $allowed = [];
        foreach ($this->staticRoutes as $other => $entries) {
            if (isset($entries[$uri])) {
                $allowed[(string) $other] = true;
            }
        }
        foreach ($this->dynamicRoutes as $other => $entries) {
            if ((string) $other === $method || isset($allowed[$other])) {
                continue;
            }
            foreach ($entries as $entry) {
                if (RouteRegex::match($entry['regex'], $uri)) {
                    $allowed[(string) $other] = true;
                    break;
                }
            }
        }
        if ($allowed !== []) {
            $methods = array_map('strval', array_keys($allowed));
            sort($methods, SORT_STRING);
            throw new MethodNotAllowedException($uri, $method, $methods);
        }
        throw new RouteNotFoundException($uri, $method);
    }

    private static function result(RouteRecord $route, array $parameters): MatchResult
    {
        return new MatchResult($route->name, $route->handler, $route->middlewares, $parameters, $route);
    }
}
