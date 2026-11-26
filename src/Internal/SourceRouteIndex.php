<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use Componenta\Http\Router\Compiler;
use Componenta\Http\Router\Contract\CompilerInterface;
use Componenta\Http\Router\Exception\RouteAlreadyExistsException;
use Componenta\Http\Router\Exception\RouteConflictException;
use Componenta\Http\Router\MatchResult;
use Componenta\Http\Router\RouteCompileResult;
use Componenta\Http\Router\RouteRecord;
use Componenta\Http\Router\Syntax\CompositeSyntax;

/** @internal Immutable source route index. */
final readonly class SourceRouteIndex
{
    private RouteMatchIndex $index;
    /** @var array<string, RouteRecord> */
    private array $records;
    /** @var array<string, RouteCompileResult> */
    private array $patterns;

    /** @param iterable<RouteRecord> $records */
    public function __construct(iterable $records, CompilerInterface $compiler)
    {
        $static = $dynamic = $patterns = $recordsByName = $names = $regexes = $protectMark = [];
        $standardSyntax = $compiler instanceof Compiler && $compiler->syntax == new CompositeSyntax();
        foreach ($records as $route) {
            $name = $route->name;
            if (isset($patterns[$name])) { throw new RouteAlreadyExistsException($name); }
            $compiled = $compiler->compile($route->path, $route->tokens, $route->defaults);
            $patterns[$name] = $compiled;
            $recordsByName[$name] = $route;
            $ordinal = count($names);
            $names[] = $name;
            $prefix = null;
            if ($compiled->hasParameters()) {
                $regex = RouteRegex::anchored($compiled->regex);
                if (!$compiler instanceof Compiler) { RouteRegex::validate($regex); }
                $regexes[$ordinal] = $regex;
                $parameterNames = $compiled->parameterNames();
                if (in_array('MARK', $parameterNames, true)) { $protectMark[$ordinal] = true; }
                $prefix = RoutePrefix::indexedKey($route->path, $standardSyntax, $compiled->tokens, $parameterNames);
            }
            foreach ($route->methods as $method) {
                if ($prefix !== null) {
                    $dynamic[$method] ??= [];
                    RoutePrefix::add($dynamic[$method], $prefix, $ordinal);
                    continue;
                }
                $previous = $static[$method][$route->path] ?? null;
                if ($previous !== null) { throw new RouteConflictException($method, $route->path, $previous, $name); }
                $static[$method][$route->path] = $name;
            }
        }
        $this->index = new RouteMatchIndex($static, $dynamic, $names, $regexes, $protectMark);
        $this->records = $recordsByName;
        $this->patterns = $patterns;
    }

    public function pattern(string $name): RouteCompileResult
    {
        return $this->patterns[$name];
    }

    /** @return array{staticRoutes: array, dynamicIndex: array, methodAliases: array} */
    public function export(): array
    {
        return $this->index->export();
    }

    public function match(string $uri, string $method): MatchResult
    {
        $name = $this->index->find($uri, $method, $matches);
        $route = $this->records[$name];
        $pattern = $this->patterns[$name];
        $parameters = $matches === [] ? $pattern->defaults : RouteParameters::extract($matches, $pattern->parameterNames(), $pattern->defaults);
        return new MatchResult($name, $route->handler, $route->middlewares, $parameters, $route);
    }
}
