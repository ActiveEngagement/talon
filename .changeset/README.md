# Changesets

This package uses [Changesets](https://github.com/changesets/changesets) to manage versioning and changelogs.

## Workflow

When you make a user-facing change, add a changeset:

```bash
pnpm changeset
```

You'll be prompted to:
1. Pick the bump type (`patch`, `minor`, `major`)
2. Write a short summary

A markdown file will be added under `.changeset/` — commit it with your PR.

When the PR merges to `main`, the **Release** GitHub Action opens (or updates) a "Version Packages" PR. Merging that PR bumps the version, updates the `CHANGELOG.md`, and creates a git tag (no npm publish — this is a PHP package and `package.json` is `private: true`).

## When NOT to add a changeset

Internal-only changes (CI, tests, refactors that don't change behavior, docs) don't need one. CI does not enforce changesets — they're optional metadata for releases.
