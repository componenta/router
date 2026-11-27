<?php

declare(strict_types=1);

use Componenta\Http\Router\RouteBuilder;

it('isolates bulk token and default values from caller references before building', function (): void {
    $pattern = '[a-z]+';
    $page = 1;
    $builder = RouteBuilder::get('item', '/items/{value}')
        ->handler('Handler')
        ->tokens(['value' => &$pattern])
        ->defaults(['page' => &$page]);

    $pattern = '[A-Z]+';
    $page = 7;
    $record = $builder->build();
    expect($record->tokens)->toBe(['value' => '[a-z]+'])
        ->and($record->defaults)->toBe(['page' => 1]);

    $builder->token('value', '\d+')->default('page', 2);
    expect($pattern)->toBe('[A-Z]+')
        ->and($page)->toBe(7)
        ->and($builder->build()->tokens)->toBe(['value' => '\d+'])
        ->and($builder->build()->defaults)->toBe(['page' => 2])
        ->and($record->tokens)->toBe(['value' => '[a-z]+'])
        ->and($record->defaults)->toBe(['page' => 1]);
});

it('keeps aliased slots and cloned builders independent', function (): void {
    $pattern = '[a-z]+';
    $page = 1;
    $original = RouteBuilder::get('item', '/items/{value}')
        ->handler('Handler')
        ->tokens(['value' => &$pattern, 'other' => &$pattern])
        ->defaults(['page' => &$page, 'other' => &$page]);

    $copy = clone $original;
    $copy->token('value', '\d+')->default('page', 2);
    expect($pattern)->toBe('[a-z]+')
        ->and($page)->toBe(1)
        ->and($copy->build()->tokens)->toBe(['value' => '\d+', 'other' => '[a-z]+'])
        ->and($copy->build()->defaults)->toBe(['page' => 2, 'other' => 1])
        ->and($original->build()->tokens)->toBe(['value' => '[a-z]+', 'other' => '[a-z]+'])
        ->and($original->build()->defaults)->toBe(['page' => 1, 'other' => 1]);
});

it('preserves merge precedence, order and deferred route validation', function (): void {
    $pattern = '[A-Z]+';
    $page = 7;
    $builder = RouteBuilder::get('item', '/items/{value}')
        ->handler('Handler')
        ->tokens(['value' => []])
        ->defaults(['page' => []]);
    expect(fn () => $builder->build())->toThrow(InvalidArgumentException::class);

    $record = $builder
        ->tokens(['other' => 'a', 'value' => &$pattern])
        ->defaults(['limit' => 10, 'page' => &$page])
        ->token('value', '\d+')
        ->default('page', 2)
        ->build();

    expect($pattern)->toBe('[A-Z]+')
        ->and($page)->toBe(7)
        ->and($record->tokens)->toBe(['value' => '\d+', 'other' => 'a'])
        ->and($record->defaults)->toBe(['page' => 2, 'limit' => 10]);
});
