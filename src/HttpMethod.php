<?php

declare(strict_types=1);

namespace Componenta\Http\Router;

/**
 * HTTP method value object
 */
final readonly class HttpMethod implements \Stringable
{
    public const string GET = 'GET';
    public const string POST = 'POST';
    public const string PUT = 'PUT';
    public const string DELETE = 'DELETE';
    public const string PATCH = 'PATCH';
    public const string HEAD = 'HEAD';
    public const string OPTIONS = 'OPTIONS';

    public function __construct(
        public string $value,
    ) {
        if (trim($value) === '') {
            throw new \InvalidArgumentException('HTTP method cannot be empty');
        }
    }

    public static function get(): self
    {
        static $instance;
        return $instance ??= new self(self::GET);
    }

    public static function post(): self
    {
        static $instance;
        return $instance ??= new self(self::POST);
    }

    public static function put(): self
    {
        static $instance;
        return $instance ??= new self(self::PUT);
    }

    public static function delete(): self
    {
        static $instance;
        return $instance ??= new self(self::DELETE);
    }

    public static function patch(): self
    {
        static $instance;
        return $instance ??= new self(self::PATCH);
    }

    public static function head(): self
    {
        static $instance;
        return $instance ??= new self(self::HEAD);
    }

    public static function options(): self
    {
        static $instance;
        return $instance ??= new self(self::OPTIONS);
    }

    public static function normalize(string $method): string
    {
        return strtoupper(trim($method));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
