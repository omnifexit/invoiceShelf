<?php

use App\Services\UblInvoiceGenerator;

test('formatAmount writes cents with two decimals', function (int $cents, string $expected) {
    expect(UblInvoiceGenerator::formatAmount($cents))->toBe($expected);
})->with([
    [0, '0.00'],
    [5, '0.05'],
    [1234, '12.34'],
    [-5, '-0.05'],
    [-123456, '-1234.56'],
]);

test('percentOfCents rounds half away from zero', function (int $cents, int $basisPoints, int $expected) {
    expect(UblInvoiceGenerator::percentOfCents($cents, $basisPoints))->toBe($expected);
})->with([
    [10000, 2100, 2100],
    [1, 2100, 0],
    [3, 2100, 1],
    [250, 200, 5],
    [50, 100, 1],
    [-50, 100, -1],
    [1999, 900, 180],
]);

test('allocate splits an amount exactly', function () {
    $parts = UblInvoiceGenerator::allocate(100, ['a' => 1, 'b' => 1, 'c' => 1]);

    expect(array_sum($parts))->toBe(100);
    expect($parts)->toBe(['a' => 34, 'b' => 33, 'c' => 33]);
});

test('allocate splits in proportion to the weights', function () {
    expect(UblInvoiceGenerator::allocate(1000, ['a' => 3000, 'b' => 1000]))
        ->toBe(['a' => 750, 'b' => 250]);
});

test('allocate of zero gives zero parts', function () {
    expect(UblInvoiceGenerator::allocate(0, ['a' => 10, 'b' => 20]))
        ->toBe(['a' => 0, 'b' => 0]);
});

test('allocate with zero weights gives everything to the first key', function () {
    expect(UblInvoiceGenerator::allocate(7, ['a' => 0, 'b' => 0]))
        ->toBe(['a' => 7, 'b' => 0]);
});

test('allocate keeps the sum for negative amounts', function () {
    $parts = UblInvoiceGenerator::allocate(-101, ['a' => 1, 'b' => 2, 'c' => 4]);

    expect(array_sum($parts))->toBe(-101);
    expect($parts['c'])->toBeLessThan($parts['a']);
});
