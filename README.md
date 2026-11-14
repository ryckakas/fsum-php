# fsum-php

[![CI](https://github.com/ryckakas/fsum-php/actions/workflows/ci.yml/badge.svg)](https://github.com/ryckakas/fsum-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/ryckakas/fsum)](https://packagist.org/packages/ryckakas/fsum)

Exact, correctly rounded float summation for PHP: the equivalent of Python's `math.fsum` and JavaScript's
`Math.sumPrecise`. No runtime dependencies.

```php
use function Fsum\sum;

$tenths = array_fill(0, 10, 0.1);

var_dump(array_sum($tenths)); // float(0.9999999999999999)
var_dump(sum($tenths));       // float(1)
```

## Why

`array_sum` and a plain loop round after every addition, so the error grows with the input and can swallow
values entirely: `array_sum([1e100, 1, -1e100])` is `0.0`. Compensated sums (Kahan, Neumaier) shrink the error
but don't remove it: Neumaier returns `1.0E+16` for `[1, 1e-100, 1e16]`, where the correctly rounded answer is
`1.0000000000000002E+16`.

`fsum` keeps the running sum exact and rounds once at the end. The result is the float nearest to the true
mathematical sum of the inputs, ties to even, whatever their order. PHP has no such function, and Packagist had no
package for it.

This is the PHP port of [ryckakas/fsum](https://github.com/ryckakas/fsum) for Go, and returns the same results
bit for bit.

## Install

```sh
composer require ryckakas/fsum
```

Requires PHP 8.2 or newer on a 64-bit build. 32-bit builds may compute floats with x87 extended precision, which
breaks exactness, so Composer refuses to install there.

## Usage

```php
use Fsum\Accumulator;
use function Fsum\sum;

// Any iterable: arrays, generators, iterators. Keys are ignored.
sum([1e100, 1, -1e100]);                             // 1.0
sum(['alice' => 0.1, 'bob' => 0.2, 'carol' => 0.3]); // 0.6 (array_sum: 0.6000000000000001)

// Ints of any size are summed exactly, not rounded to float first.
sum([2 ** 53 + 1, -(2.0 ** 53)]);                    // 1.0 (array_sum: 0.0)

// A running sum. sum() doesn't consume it, and clone makes an independent copy.
$accumulator = new Accumulator();
foreach ([1e16, 1, 1e-16] as $value) {
    $accumulator->add($value);
}
$accumulator->sum();                                 // 1.0000000000000002E+16
```

Values must be `int` or `float`. `sum()` throws a `TypeError` naming the key of anything else, including numeric
strings: `"0.1"` has no single exact meaning, and `Math.sumPrecise` rejects strings too. Convert them yourself
first. `Accumulator::add()` uses a native `int|float` type, so it follows your file's `strict_types` mode.

## Semantics

`fsum` follows JavaScript's `Math.sumPrecise`:

| Input | `Fsum\sum` | `array_sum` |
|---|---|---|
| `[]`, or every value `-0.0` | `-0.0` | `0` / `0.0` |
| `[INF, -INF]` | `NAN` | `NAN` |
| `[PHP_FLOAT_MAX, PHP_FLOAT_MAX, -PHP_FLOAT_MAX]` | `PHP_FLOAT_MAX` | `INF` (intermediate overflow) |
| `[1e308, 1e308]` | `INF` | `INF` |
| `['1.5']` | `TypeError` | `1.5` |
| any other input | the correctly rounded sum | the sum rounded after every addition |

In full: an empty or all `-0.0` input sums to `-0.0`; any `NAN`, or both infinities, gives `NAN`; otherwise an
infinite input gives that infinity; otherwise the exact sum is rounded once, overflowing to `±INF` only when that
final rounding does.

### Guarantees

1. Valid input never throws, whatever float or int values it contains.
2. The result does not depend on the order of the inputs.
3. The result is bit-identical to `Math.sumPrecise` (and to the Go library), except that every NaN result is the
   `NAN` constant.

## How it's tested

- **The golden corpus** of the Go library: 1,033 cases with expected values from exact rational arithmetic,
  checked there against V8's own `Math.sumPrecise`. Every case runs through `sum()`, a generator, reversed input
  and an `Accumulator` read midway.
- **A second, independent oracle**: an exact sum in bcmath, which itself agrees with the whole corpus. Seeded
  random inputs (cancellation, subnormals, near overflow, rounding ties, hundreds of partials, full-range ints)
  are compared against it in every order, and `composer soak` keeps going on random seeds for as long as you
  like.
- CI runs on PHP 8.2 to 8.5 plus 8.5 with the JIT on, with a 100% line-coverage floor and mutation testing
  (Infection).

## Performance

Exactness costs time, and in PHP more than in compiled languages. Each value is added into a short list of
non-overlapping partial sums, so the cost per value grows with how many partials the data keeps alive: one to five
for everyday data, dozens for data spread over the whole float range.

Time to sum 1,000,000 floats (PHP 8.5.12, `php bench/bench.php`, JIT off / `opcache.jit=tracing`):

| Data | `array_sum` | Plain loop | Kahan | Neumaier | `Fsum\sum` | vs plain loop |
|---|---|---|---|---|---|---|
| Uniform in [0, 1) | 2.9 ms | 9.6 / 3.2 ms | 21.8 / 7.1 ms | 39.8 / 16.9 ms | 343 / 160 ms | 36x / 49x |
| Canceling pairs, shuffled | 2.9 ms | 9.7 / 3.3 ms | 21.6 / 7.2 ms | 40.4 / 17.2 ms | 823 / 372 ms | 85x / 114x |
| Exponents spread over 2^-300 to 2^300 | 3.0 ms | 9.6 / 4.0 ms | 23.6 / 7.4 ms | 40.4 / 16.9 ms | 2,021 / 942 ms | 211x / 233x |
| Exponents spread over the whole float range | 2.9 ms | 9.5 / 3.3 ms | 22.8 / 7.3 ms | 39.7 / 17.2 ms | 5,448 / 2,411 ms | 573x / 734x |

About 0.3 µs per value on everyday data is fine for totals, reports and statistics. On hot paths over millions of
values where the last bit doesn't matter, keep `array_sum`.

## Credits

- Jonathan Richard Shewchuk, "Adaptive Precision Floating-Point Arithmetic and Fast Robust Geometric
  Predicates" (1997): the non-overlapping partials.
- Python's [`math.fsum`](https://github.com/python/cpython/blob/main/Modules/mathmodule.c): the summation
  loop and the half-way rounding correction.
- The TC39 [`Math.sumPrecise` proposal](https://github.com/tc39/proposal-math-sum): the semantics and the
  overflow handling.
- [ryckakas/fsum](https://github.com/ryckakas/fsum): the reference implementation and the golden corpus.

## License

[MIT](LICENSE)
