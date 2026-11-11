# ADR 0001 — One canonical repository

**Date:** 2026-11-10
**Status:** Accepted

## Context

The project existed as two git repositories with unrelated histories:

1. The outer repository (`tentukottasite/`) held the security audit, tests, docs and the
   application source. It had no remote.
2. `_public_html/.git` held the same application source, the user uploads, and the GitHub
   remote `thisisjayakumar/tentukotta-latest-php`.

The two trees were verified byte-identical except for the Stripe README (redacted in the
inner repo) and the ignore file. Keeping both would duplicate every future change and make
deployments ambiguous.

## Decision

- The **outer repository is canonical**. It contains `_public_html/`, `docs/`, `tests/`,
  `deploy.sh` and the deployment configuration.
- The nested `_public_html/.git` is removed. Its history is preserved as a bundle in
  `~/.private_tentukotta/repo-backups/` and as the remote tag
  `archive/pre-consolidation-2026-11-10`.
- User media is **not tracked**. `uploads/` keeps only the guard files; media lives on the
  host and in backups.
- Historical credentials and the Stripe test key are redacted from every reachable commit
  before pushing.
- The previous remote `main` is replaced by force-push; collaborators must re-clone or reset
  to `origin/main`.

## Consequences

- One place to change code, one deployment artifact, one history.
- The remote history was rewritten again; old clones are stale by design.
- Uploads must be backed up separately from git (Hostinger backups + off-host dump).
- Secret rotation is still required: redaction does not un-leak a key that was pushed.
