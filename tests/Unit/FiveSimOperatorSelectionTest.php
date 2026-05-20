<?php

use App\Services\FiveSimService;

test('it selects the operator with the highest price', function () {
    $operators = [
        'mts' => ['cost' => 12.5, 'count' => 100, 'rate' => 85],
        'beeline' => ['cost' => 15.0, 'count' => 50, 'rate' => 90],
        'tele2' => ['cost' => 10.0, 'count' => 200, 'rate' => 95],
        'any' => ['cost' => 8.0, 'count' => 500, 'rate' => 80],
    ];

    $best = FiveSimService::selectBestOperator($operators);

    expect($best)->toBe('beeline');
});

test('it selects the operator with the highest rate when costs are equal', function () {
    $operators = [
        'mts' => ['cost' => 15.0, 'count' => 100, 'rate' => 85],
        'beeline' => ['cost' => 15.0, 'count' => 50, 'rate' => 95], // higher rate
        'tele2' => ['cost' => 10.0, 'count' => 200, 'rate' => 99],
    ];

    $best = FiveSimService::selectBestOperator($operators);

    expect($best)->toBe('beeline');
});

test('it ignores operators with zero or negative stock count', function () {
    $operators = [
        'mts' => ['cost' => 25.0, 'count' => 0, 'rate' => 99], // no stock
        'beeline' => ['cost' => 15.0, 'count' => 50, 'rate' => 90],
        'tele2' => ['cost' => 10.0, 'count' => -5, 'rate' => 95], // negative stock
    ];

    $best = FiveSimService::selectBestOperator($operators);

    expect($best)->toBe('beeline');
});

test('it ignores virtual any operator', function () {
    $operators = [
        'any' => ['cost' => 50.0, 'count' => 100, 'rate' => 99], // virtual any has highest price
        'mts' => ['cost' => 10.0, 'count' => 100, 'rate' => 85],
    ];

    $best = FiveSimService::selectBestOperator($operators);

    expect($best)->toBe('mts');
});

test('it falls back to any if no valid operator has stock', function () {
    $operators = [
        'mts' => ['cost' => 10.0, 'count' => 0, 'rate' => 85],
        'beeline' => ['cost' => 15.0, 'count' => 0, 'rate' => 90],
    ];

    $best = FiveSimService::selectBestOperator($operators);

    expect($best)->toBe('any');
});
