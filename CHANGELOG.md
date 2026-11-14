# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-11-14

### Changed

- The engine is now a superaccumulator (Radford Neal's xsum), as in [ryckakas/fsum](https://github.com/ryckakas/fsum)
  v0.2.0 for Go: the cost per value no longer depends on the data. Summing a million floats is 3 times faster for
  uniform data and 40 times faster for data spread over the whole float range. Results are unchanged, bit for bit.
- `sum()` of one or two floats is about 3 times faster; of three to ten values it is about 1 µs slower, the fixed
  cost of rounding.
- An `Accumulator` now holds up to 2,046 floats it has not folded in yet, about 33 KB at most.

## [0.1.0] - 2026-11-14

### Added

- `Fsum\sum(iterable $values): float`: the exact sum of ints and floats, rounded once to the nearest float, with
  JavaScript `Math.sumPrecise` semantics. Bit-identical to [ryckakas/fsum](https://github.com/ryckakas/fsum)
  v0.1.0 for Go.
- `Fsum\Accumulator`: a running sum with `add()`, a repeatable `sum()` and `reset()`.
- Ints of any size are summed exactly. Values that are neither int nor float throw `TypeError`.
- Requires PHP 8.2 or newer on a 64-bit build.

[Unreleased]: https://github.com/ryckakas/fsum-php/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/ryckakas/fsum-php/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/ryckakas/fsum-php/releases/tag/v0.1.0
