# Contributing

## Branching (trunk-based, short-lived branches)
- `main` is always releasable and protected (CI green + 1 approval required).
- Branch off `main`: `type/scope-short-desc`, e.g. `feat/cropper-border-scan`.
- Keep branches under ~2 days; rebase on `main` before opening a PR.

## Commits (Conventional Commits)
`type(scope): summary` in imperative mood, e.g. `feat(clusterer): add silhouette k selection`.
Types: `feat`, `fix`, `test`, `docs`, `refactor`, `perf`, `chore`, `ci`.

## Pull requests
- Small and single-purpose. Fill in what/why + test evidence.
- CI (cs-fixer + PHPStan L8 + PHPUnit on 8.3/8.4/8.5) must pass before review.
- One non-author approval from the mapped `CODEOWNERS` reviewer. No self-merge.
- **Squash-merge** into `main`.

## Frozen contracts
Any change under `src/Contracts` or `src/Options` requires an ADR in `docs/` and
approval from all three developers.

## Local checks
```
composer install
composer cs      # coding standards (PSR-12)
composer stan    # PHPStan level 8
composer test    # PHPUnit
```
