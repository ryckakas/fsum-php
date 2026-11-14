# AGENTS.md

Guidance for coding agents in this repository. `CLAUDE.md` only imports this file, so edit this one.

## What this is

`ryckakas/fsum` on Packagist: exact, correctly rounded float sums for PHP with JavaScript `Math.sumPrecise`
semantics. It is the PHP port of [github.com/ryckakas/fsum](https://github.com/ryckakas/fsum) (Go), which is the
reference implementation and owns the golden corpus generator. Behaviour must stay bit-identical to it.

## Commands

```bash
composer check          # cs + stan + complexity + test + README example: the gate before any commit
composer check:ci       # everything the CI matrix runs: validate, cs, stan, complexity, coverage floor, mutation

composer test           # phpunit
composer stan           # phpstan analyse (level max)
composer cs             # php-cs-fixer --dry-run --diff (exit 8 = needs fixing)
composer cs:fix         # apply the fixes
composer complexity     # bonsai-lint cognitive complexity gate (threshold 15, no baseline)
composer coverage       # phpunit + Clover + tools/check-coverage.php (floor: 100%)
composer mutation       # infection (floors: minMsi 94 / minCoveredMsi 94)
composer bc             # public API vs the latest release tag (Roave BackwardCompatibilityCheck)
composer soak -- 600    # random seeds against the bcmath oracle for 600 s (default 60): run before a release
```

Pass extra flags through Composer with `--`: `composer stan -- --no-progress`.

### Environment gotchas

- **`composer cs` needs `--sequential` on Windows**: php-cs-fixer's parallel runner can hang there.
- **Line endings are load-bearing.** `.gitattributes` pins `eol=lf` because php-cs-fixer's PSR-12 rules require
  LF; a CRLF checkout fails `composer cs` locally while CI passes.
- **`coverage` and `mutation` need a coverage driver** (xdebug or pcov). Without one they cannot run; say so
  rather than claiming they passed. The php.net Windows builds ship none; a `php:8.x-cli` container with
  xdebug works.
- **`composer bc` needs PHP 8.4+ with intl and its own install.** The checker lives in `tools/bc-check` with its
  own `composer.json` and committed lock, because it requires PHP 8.4 and the library's `require-dev` must install
  on 8.2. Run `composer install -d tools/bc-check` once. It compares committed code and needs the tags locally.
- **`composer complexity` downloads its binary once** (bonsai-lint is a launcher). Offline, point
  `BONSAI_LINT_BINARY` at a local build.

## Architecture

- `src/functions.php` holds `Fsum\sum()`, loaded through Composer's `files` autoload. It checks each value's
  type (PHP cannot enforce an iterable's element type) and hands floats to an `Accumulator` in blocks of 2046.
- `src/Accumulator.php` holds the `Math.sumPrecise` rules (-0.0, NAN, infinities) around the engine, as the Go
  `state.go` does, plus a buffer of pending floats: PHP has no way to read one float's bits cheaply, so the bits
  are read a block at a time with `pack`/`unpack`. `addFloats()` is `@internal`, for `sum()` only.
- `src/Superaccumulator.php` (`@internal`) is the engine, ported from the Go `xsum.go`: 67 overlapping int
  chunks, a carry pass every 2046 adds, and rounding of the magnitude. Keep the two in step; a fix found here is
  filed against the Go repository too. Two things differ on purpose: PHP ints overflow into floats, so no chunk
  may ever reach 2^63, and PHP has no unsigned ints, so rounding works on 62 bits where Go takes 64.
- Ints go straight into two chunks as integers, so ints above 2^53 are summed exactly instead of being rounded
  first.

## Testing

Tests are organised by ZOMBIES section comments. Expected values come from the golden corpus, compared by bit
pattern (`GoldenCorpus::hex()`), so -0.0 differs from 0.0 and a NaN result must be the canonical `NAN`. Never
hand-compute an expected float beyond exact small cases. Each corpus test loops over all corpus cases and lists
every mismatch: a data provider per case made each mutant run 4000 tests, and Infection skipped them as too slow.

Three independent references: the golden corpus; `tests/Support/Shewchuk.php`, the v0.1 engine, test-only and
fast, so it is the one long inputs and Infection use; and `tests/Support/Oracle.php`, which sums exactly with
bcmath (each value as an integer count of 2^-1074) and lets PHP's correctly rounded string-to-float cast round once.
`DifferentialTest` checks the oracle against the corpus, then runs seeded `RandomInputs` (the Go generator's
classes plus large ints) through `sum()`, shuffled input and an Accumulator. A soak failure prints a class and
seed; `DifferentialTest::check($class, $seed)` replays it. These tests are in the `oracle` group, which Infection
skips for speed.

`PropertiesTest` holds what must be true for any engine (negation, carry passes, ties broken far below);
`SuperaccumulatorTest` tests chunk states directly, including ones no real input reaches.

Coverage floor is **100%**. Mutation floors are **94**: the 19 surviving mutants are equivalent, their categories
are in `infection.json5`, and an escaped mutant outside those categories is a missing test.

## Docs

`README.md` is the landing page and makes promises: `examples/example.php` runs its examples in CI and fails if a
result drifts. `CHANGELOG.md` (Keep a Changelog) is the behavioural record. A change to behaviour updates both in
the same PR, under `[Unreleased]`.

## Golden corpus

`testdata/golden.txt` is vendored unchanged from the Go repository; `testdata/README.md` records the source tag
and hash, and `tests/GoldenCorpusTest.php` pins the hash. Never edit it here: change the generator in the Go
repository, release there, then re-vendor.

## Comments

No comment unless it explains why, and none longer than 3 lines. Type-bearing PHPStan tags are not prose. No em
dashes in docs or comments.

## Repository conventions

- `main` takes changes through pull requests only (ruleset "main: pull requests only"). Every CI job name is a
  required check; renaming a job silently un-requires it, so update the ruleset in the same change.
- **Workflows are audited like code.** Every `uses:` is pinned to a full commit SHA with the release in a trailing
  comment, every checkout sets `persist-credentials: false`, and the token is read-only. zizmor and actionlint
  fail CI otherwise. Images in `run:` steps are pinned by digest.
- **The version moves when compatibility does.** The required **Public API** job (`composer bc`) compares the PR
  against the latest release tag and fails on any break: a removed function, class or method, a changed
  signature, a narrowed type. Everything autoloadable is public unless marked `@internal`. A break made on purpose
  is declared in the same PR twice: an `<ignored-regex>` per printed `[BC]` line, with an XML comment giving the
  reason, in `.roave-backward-compatibility-check.xml` at the root (create it if absent); and under
  `[Unreleased]` in `CHANGELOG.md`, which commits the next tag to 0.(x+1).0 while below 1.0. The release PR for
  that tag deletes the file.
- Dev dependencies are pinned to **exact** versions, which makes `composer audit` actionable. `require` stays a
  range: it is a compatibility contract.
- Releases are tag-driven and Packagist syncs by webhook. `composer.json` carries no `version` field.
  `.gitattributes` `export-ignore` keeps dev files out of the dist archive; a new dev-only root file gets a line
  there too.
