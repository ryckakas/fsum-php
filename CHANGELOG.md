# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `Fsum\sum(iterable $values): float`: the exact sum of ints and floats, rounded once to the nearest float, with
  JavaScript `Math.sumPrecise` semantics. Bit-identical to [ryckakas/fsum](https://github.com/ryckakas/fsum)
  v0.1.0 for Go.
- `Fsum\Accumulator`: a running sum with `add()`, a repeatable `sum()` and `reset()`.
- Ints of any size are summed exactly. Values that are neither int nor float throw `TypeError`.
- Requires PHP 8.2 or newer on a 64-bit build.
