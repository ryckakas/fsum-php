# AGENTS.md

Guidance for coding agents in this repository. `CLAUDE.md` only imports this file, so edit this one.

## What this is

`ryckakas/fsum` on Packagist: exact, correctly rounded float sums for PHP with JavaScript `Math.sumPrecise`
semantics. It is the PHP port of [github.com/ryckakas/fsum](https://github.com/ryckakas/fsum) (Go), which is the
reference implementation and owns the golden corpus generator. Behaviour must stay bit-identical to it.

## Commands

```bash
composer check          # cs + stan + complexity + test: the gate before any commit

composer test           # phpunit
composer stan           # phpstan analyse (level max)
composer cs             # php-cs-fixer --dry-run --diff (exit 8 = needs fixing)
composer cs:fix         # apply the fixes
composer complexity     # bonsai-lint cognitive complexity gate (threshold 15, no baseline)
```

Pass extra flags through Composer with `--`: `composer stan -- --no-progress`.

### Environment gotchas

- **`composer cs` needs `--sequential` on Windows**: php-cs-fixer's parallel runner can hang there.
- **Line endings are load-bearing.** `.gitattributes` pins `eol=lf` because php-cs-fixer's PSR-12 rules require
  LF; a CRLF checkout fails `composer cs` locally while CI passes.
- **`composer complexity` downloads its binary once** (bonsai-lint is a launcher). Offline, point
  `BONSAI_LINT_BINARY` at a local build.

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
- Dev dependencies are pinned to **exact** versions, which makes `composer audit` actionable. `require` stays a
  range: it is a compatibility contract.
- Releases are tag-driven and Packagist syncs by webhook. `composer.json` carries no `version` field.
  `.gitattributes` `export-ignore` keeps dev files out of the dist archive; a new dev-only root file gets a line
  there too.
