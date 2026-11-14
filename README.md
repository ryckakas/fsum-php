# fsum-php

![fsum-php: exact, correctly rounded float summation for PHP](.github/cover.jpg)

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
first. `Accumulator::add()` uses a native `int|float` type, so it follows your file's `strict_types` mode: without
it, PHP converts numeric strings (`"1e400"` becomes `INF`) and bools (`true` adds 1) before they reach the sum, and
anything else throws a `TypeError`.

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
- **A second exact engine**: the Shewchuk implementation from v0.1, kept in the tests and compared with the
  current one on inputs thousands of values long, on and around every carry pass, and on ties broken by a single
  bit up to 130 places below.
- **The accumulator itself**: random internal states, including ones no real input reaches in a test, rounded
  against bcmath; negating every input must negate the result.
- CI runs on PHP 8.2 to 8.5, plus 8.5 with and without the JIT on arm64 and with the JIT on x86-64, with a 100%
  line-coverage floor and mutation testing (Infection).

## Performance

Exactness costs time, but a constant amount per value. The running sum is a superaccumulator: 67 overlapping
integer chunks, where adding a value touches two chunks and never carries. The cost does not depend on the data.

Time to sum 1,000,000 floats (PHP 8.5.4, Apple M5, `php bench/bench.php`, JIT off / `opcache.jit=tracing`):

| Data | `array_sum` | Plain loop | Kahan | Neumaier | `Fsum\sum` | vs plain loop |
|---|---|---|---|---|---|---|
| Uniform in [0, 1) | 1.4 ms | 2.8 / 1.0 ms | 14.0 / 2.8 ms | 16.2 / 7.1 ms | 45 / 20 ms | 16x / 21x |
| Canceling pairs, shuffled | 1.3 ms | 3.0 / 1.0 ms | 14.0 / 2.8 ms | 15.7 / 7.0 ms | 48 / 23 ms | 16x / 23x |
| Exponents spread over 2^-300 to 2^300 | 1.4 ms | 3.1 / 1.0 ms | 14.1 / 2.8 ms | 16.1 / 7.1 ms | 48 / 24 ms | 15x / 23x |
| Exponents spread over the whole float range | 1.4 ms | 2.9 / 1.0 ms | 14.0 / 2.8 ms | 16.0 / 6.9 ms | 48 / 23 ms | 17x / 23x |

About 0.05 µs per value is fine for totals, reports and statistics. On hot paths over millions of values where
the last bit doesn't matter, keep `array_sum`.

Short inputs pay a fixed cost for rounding: without the JIT, `sum()` takes about 0.09 µs for one or two floats,
1.5 to 2.5 µs for three to ten values and 6 to 8 µs for a hundred. `Accumulator::add()` costs about 0.05 µs per value. An
`Accumulator` holds up to 2,046 floats it has not folded into the chunks yet, about 33 KB at most: PHP reads float
bits much faster a block at a time.

## Credits

- Radford M. Neal, "Fast exact summation using small and large superaccumulators" (2015) and his
  [xsum](https://gitlab.com/radfordneal/xsum): the superaccumulator. `src/Superaccumulator.php` carries its
  licence notice.
- Jonathan Richard Shewchuk, "Adaptive Precision Floating-Point Arithmetic and Fast Robust Geometric
  Predicates" (1997): the non-overlapping partials of the first engine, which the tests still compare against.
- Python's [`math.fsum`](https://github.com/python/cpython/blob/main/Modules/mathmodule.c): the summation
  loop and the half-way rounding correction of that first engine.
- The TC39 [`Math.sumPrecise` proposal](https://github.com/tc39/proposal-math-sum) and its polyfill by Kevin
  Gibbons: the semantics, and the overflow handling of the first engine. `tests/Support/Shewchuk.php` carries the
  licence notices.
- [ryckakas/fsum](https://github.com/ryckakas/fsum): the reference implementation and the golden corpus.

## License

[MIT](LICENSE)
