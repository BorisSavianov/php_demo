# Contributing

Thanks for working on **image-color-analyzer**. This guide covers the workflow, review
rules, and local checks. For how the code is organized, read the
[architecture overview](docs/architecture.md); for the rules around changing interfaces, see
[frozen contracts](docs/contracts.md).

## Branching

Trunk-based development with short-lived branches:

- `main` is always releasable and protected — CI must be green and one approval is required.
- Branch off `main` using `type/scope-short-desc`, e.g. `feat/cropper-border-scan`.
- Keep branches short (under ~2 days) and rebase on `main` before opening a pull request.

## Commits

Follow [Conventional Commits](https://www.conventionalcommits.org/): `type(scope): summary`
in the imperative mood, e.g. `feat(clusterer): add silhouette k selection`.

Allowed types: `feat`, `fix`, `test`, `docs`, `refactor`, `perf`, `chore`, `ci`.

## Pull requests

- Keep each PR small and single-purpose. Describe **what** changed and **why**, and include
  test evidence.
- CI must pass before review: **php-cs-fixer** (PSR-12), **PHPStan level 8**, and **PHPUnit**
  across PHP 8.2 / 8.3 / 8.4 / 8.5 (plus the Imagick-adapter job on 8.4).
- Exactly one non-author approval from the mapped [`CODEOWNERS`](CODEOWNERS) reviewer. No
  self-merge.
- **Squash-merge** into `main` so history stays one clean commit per PR.

## Changing the frozen contracts

Any change under `src/Contracts` or `src/Options` is a coordination point. It requires:

1. an [ADR](docs) in `docs/` explaining the decision and migration,
2. approval from the affected module owners, and
3. the corresponding documentation updates (README, module guides, dependent ADRs) in the
   same PR.

See [frozen contracts](docs/contracts.md) for the full policy.

## Local checks

Run the same checks CI does before pushing:

```bash
composer install
composer cs      # coding standards (PSR-12, php-cs-fixer)
composer stan    # static analysis (PHPStan level 8)
composer test    # unit + integration tests (PHPUnit)
```

`composer cs-fix` applies the formatter instead of just reporting. For manual verification
against real images, see the [testing guide](docs/testing.md).
