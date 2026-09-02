---
name: project-resonate-maintenance
description: Resonate's dependency-bump and changelog workflow (Pint added as dev dependency 2026-07-22)
metadata:
  type: project
---

Resonate (`webpatser/resonate`, namespace `Webpatser\Resonate`) is a Fiber-based drop-in
replacement for `laravel/reverb`. Its maintenance workflow has a recurring shape worth
knowing before touching it again:

- **Dependency bumps travel together.** `composer update` pulls `laravel/framework` (and
  the matching `illuminate/*` constraints, which resolve from the `laravel/framework`
  monorepo split, not standalone packages) alongside `webpatser/fledge-fiber`, which is
  versioned to track the same Laravel release plus a fourth "Resonate patch" digit
  (e.g. `v13.21.1.0`).
- **The changelog's `Unreleased` section doubles as an upstream parity log.** Each
  routine dependency bump gets a "Verify against Laravel vX.Y.Z" entry that explicitly
  checks whether the corresponding `laravel/reverb` release (Resonate's upstream
  reference implementation) shipped anything worth porting, and calls out which of
  Resonate's own Broadcasting/Redis/Queue dependencies were touched in the Laravel
  diff window. Entries get folded into the next tagged release rather than kept
  separate; stale "no releases since vX" claims in that section should be corrected
  or removed, not left in place, when a newer Reverb release exists.
- **Pint is a dev dependency since 2026-07-22.** Run `vendor/bin/pint` before
  finishing any change here (default Laravel preset, no pint.json). The codebase
  uses plain classes (no `final`); match sibling classes for PHPDoc conventions.

**Why:** knowing this ahead of time avoids re-discovering the composer/changelog
pattern from scratch, and avoids a wasted `vendor/bin/pint` call, each time this repo
comes up for a routine "verify against latest Laravel" or upstream-parity task.

**How to apply:** when asked to verify Resonate against a new Laravel/Reverb release,
follow this same shape (bump both `laravel/framework` and `fledge-fiber` together,
diff the Reverb release for portable fixes, rewrite rather than append to
`Unreleased`, run Pint before finishing). See [[project_resonate]] in the global cross-repo memory
store for the package's origin and v0.1.0 publish history.
