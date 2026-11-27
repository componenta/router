<?php

declare(strict_types=1);

namespace Componenta\Http\Router\Internal;

use Componenta\Http\Router\Exception\MethodNotAllowedException;
use Componenta\Http\Router\Exception\RouteNotFoundException;
use Componenta\Http\Router\HttpMethod;

/** @internal Shared lookup algorithm for source and prepared definitions. */
final readonly class RouteMatchIndex
{
    /** @var array<string, int> */
    private array $maxPrefixLengths;

    /**
     * @param array<string, array<string, string>> $static
     * @param array<string, array<string, list<int>>> $dynamic
     * @param array<int, string> $names
     * @param array<int, string> $regexes
     * @param array<int, true> $protectMark
     */
    public function __construct(
        private array $static,
        private array $dynamic,
        private array $names,
        private array $regexes,
        private array $protectMark,
    ) {
        $this->maxPrefixLengths = array_map(RoutePrefix::maxPrefixLength(...), $dynamic);
    }

    public function find(string $uri, string $method, ?array &$matches): string
    {
        $method = HttpMethod::normalize($method);
        $uri = str_starts_with($uri, '/') ? $uri : '/' . $uri;
        $matches = [];
        if (isset($this->static[$method][$uri])) {
            return $this->static[$method][$uri];
        }
        foreach (RoutePrefix::candidates($this->dynamic[$method] ?? [], $uri, $this->maxPrefixLengths[$method] ?? 0) as $index) {
            if (RouteRegex::match($this->regexes[$index], $uri, $matches, isset($this->protectMark[$index]))) {
                return $this->names[$index];
            }
        }
        $allowed = [];
        foreach ($this->static as $other => $paths) {
            if (isset($paths[$uri])) { $allowed[(string) $other] = true; }
        }
        foreach ($this->dynamic as $other => $buckets) {
            if ((string) $other === $method) { continue; }
            foreach (RoutePrefix::candidates($buckets, $uri, $this->maxPrefixLengths[$other]) as $index) {
                if (RouteRegex::match($this->regexes[$index], $uri)) {
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

    /** @return array{staticRoutes: array, dynamicIndex: array, methodAliases: array} */
    public function export(): array
    {
        $aliases = RouteMethodGroups::aliases($this->dynamic);
        return [
            'staticRoutes' => $this->static,
            'dynamicIndex' => array_diff_key($this->dynamic, $aliases),
            'methodAliases' => $aliases,
        ];
    }
}
