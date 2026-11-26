<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Syntax\CompositeSyntax;
use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteConflictException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\HttpMethod;
use Componenta\Http\Router\Exception\RouteAlreadyExistsException;
use Componenta\Http\Router\RouteCompileResult;
use Componenta\Http\Router\RouteRecord;

/** @internal Immutable source route index. Construction never modifies the collection. */
final readonly class SourceRouteIndex
{
    /** @var array<string, array<string, RouteRecord>> */
    private array $staticRoutes;
    /** @var array<string, list<array{route: RouteRecord, regex: string, compiled: RouteCompileResult, protectMark: bool}>> */
    private array $dynamicRoutes;
    /** @var array<string, RouteCompileResult> */
    private array $patterns;
    /** @var array<string, array<string, list<int>>> */
    private array $prefixIndex;

    /** @param iterable<RouteRecord> $records */
    public function __construct(iterable $records, CompilerInterface $compiler)
    {
        $static = $dynamic = $patterns = $prefixIndex = [];
        $standardSyntax = $compiler instanceof Compiler && $compiler->syntax == new CompositeSyntax();
        foreach ($records as $route) {
            if (isset($patterns[$route->name])) {
                throw new RouteAlreadyExistsException($route->name);
            }
            $compiled = $compiler->compile($route->path, $route->tokens, $route->defaults);
            $patterns[$route->name] = $compiled;
            $regex = RouteRegex::anchored($compiled->regex);
            if ($compiled->hasParameters() && !$compiler instanceof Compiler) {
                RouteRegex::validate($regex);
            }
            $prefix = $compiled->hasParameters()
                ? RoutePrefix::indexedKey($route->path, $standardSyntax, $compiled->tokens, $compiled->parameterNames())
                : null;
            foreach ($route->methods as $method) {
                if ($prefix !== null) {
                    $prefixIndex[$method][$prefix][] = count($dynamic[$method] ?? []);
                    $dynamic[$method][] = ['route' => $route, 'regex' => $regex, 'compiled' => $compiled, 'protectMark' => in_array('MARK', $compiled->parameterNames(), true)];
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
        $this->prefixIndex = $prefixIndex;
    }

    public function pattern(string $name): RouteCompileResult
    {
        return $this->patterns[$name];
    }

    public function match(string $uri, string $method): MatchResult
    {
        $method = HttpMethod::normalize($method);
        $uri = str_starts_with($uri, '/') ? $uri : '/' . $uri;

        $static = $this->staticRoutes[$method][$uri] ?? null;
        if ($static !== null) {
            return self::result($static, $this->patterns[$static->name]->defaults);
        }

        foreach (RoutePrefix::candidates($this->prefixIndex[$method] ?? [], $uri) as $index) {
            $entry = $this->dynamicRoutes[$method][$index];
            if (RouteRegex::match($entry['regex'], $uri, $matches, $entry['protectMark'])) {
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
            if ((string) $other === $method) {
                continue;
            }
            foreach (RoutePrefix::candidates($this->prefixIndex[$other], $uri) as $index) {
                $entry = $entries[$index];
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
