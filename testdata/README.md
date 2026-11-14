# Golden corpus

`golden.txt` is copied unchanged from [github.com/ryckakas/fsum](https://github.com/ryckakas/fsum), the Go
implementation, which owns the generator:

| | |
|---|---|
| Source | `testdata/golden.txt` at tag `v0.1.0` (commit `c3a4c8e29141e05a9166755e017095892a207422`) |
| SHA-256 | `8225c53171fdc3c16206f4a80b258e8d99e9550c692d00aebf2afe079055ee0c` |
| Cases | 1033 |

Each line is `name<TAB>expected<TAB>inputs`, every value a float64 bit pattern in big-endian hex. The expected
sums come from exact rational arithmetic (Python `fractions.Fraction`) with JavaScript `Math.sumPrecise` rules,
and the Go repository cross-checks them against V8's `Math.sumPrecise`.

To change a case, change `scripts/gen_golden.py` in the Go repository, release it there, then copy the new file
here and update this table and the hash in `tests/GoldenCorpusTest.php`.
