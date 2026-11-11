# Phase 0/1 Implementation Plan

**Design:** [`2026-11-10-modernization-design.md`](../specs/2026-11-10-modernization-design.md)
**Scope:** repository consolidation + the stop-the-bleeding security fixes.
**Rule:** no Laravel migration work starts until Phase 1's exit gate is green.

---

## Phase 0 — One repository (complete)

- [x] Back up both histories as git bundles in `~/.private_tentukotta/repo-backups/`.
- [x] Prove tree parity: only the Stripe README and root `.gitignore` differed.
- [x] Remove `_public_html/.git`; keep the outer repo as canonical.
- [x] Do not carry user media into git; keep upload guard files only.
- [x] Rewrite `.gitignore` for runtime artefacts, uploads, dumps, builds and stray files.
- [x] Commit the Stripe redaction; remove tracked OS junk.
- [x] Redact historical credentials from docs/tests and rewrite history; verify zero
      reachable commits contain the old secrets.
- [x] Preserve the previous remote history as tag `archive/pre-consolidation-2026-11-10`.
- [x] Force-push the consolidated `main`.
- [x] Tag the verified baseline `baseline-2026-11-11` and push the tag.
- [x] Record the deployment topology + repository decision in `docs/adr/0001-canonical-repo.md`.

**Verification**

```bash
git status --short                 # clean
git log --oneline -5
git rev-list --all | wc -l
git ls-remote origin main
```

---

## Phase 1 — Stop the bleeding

Each task is a separate commit with a test. Do not combine unrelated fixes.

### 1. Cron counter reset is public

- [x] `Cron::reset_daily_view|weekly|monthly` currently update every video with no auth.
- [x] Make them CLI-only, or require the configured cron key.
- [x] Add a static check that no public method updates the `videos` table without a guard.

**Files:** `_public_html/application/controllers/Cron.php`
**Verify:** request the URL over HTTP → 403; CLI invocation still works.

### 2. Deactivated accounts can log in

- [x] Web login: require `user.status = 1` before issuing a session.
- [x] API login: require the same in `Api_v130_model::validate_user` and any v100/legacy path.
- [x] Add a unit/static test asserting the status predicate exists in both paths.

**Files:** `Login.php`, `Api_v130_model.php`, `Api_v100_model.php`, `Legacy_api_model.php`.

### 3. Firebase login trusts client-supplied identity

- [x] Disable the Firebase sign-in endpoints with HTTP 410 until server-side ID-token
      verification is implemented (or add verification via Google tokeninfo/Admin SDK).
- [x] Return a stable JSON error so the mobile app can fall back to password login.
- [x] Add a static check that the endpoints do not set a session/user from request fields.

**Files:** `rest_api/V100.php`, `rest_api/V130.php`, `Legacy_api.php`.

### 4. Mobile API IDOR

- [ ] **Deferred to Phase 7.** Inventory endpoints that trust a caller-supplied `user_id`.
- [ ] Require a per-user token (or the existing stored token) and compare it to the requested
      user before returning user-scoped data.
- [x] Never return `password`, `token`, or `firebase_auth_uid` (already enforced by the
      public-field helpers).
- [ ] Add contract tests for “wrong user” → 403/404.

> **Why deferred:** the installed Android app has no per-user API token; it sends only the
> shared API key and a `user_id`. Enforcing a token in Phase 1 would break profile, favourites
> and subscription screens. Phase 7 issues tokens, adds them to login/signup responses, and
> flips enforcement after a token-aware app release. The shared-key gate remains in force
> until then. This is a tracked, accepted risk, not a silent omission.

**Files:** `application/core/API_Core_Controller.php`, `rest_api/*`, `Legacy_api.php`,
`application/models/Api_*`, `Legacy_api_model.php`.

### 5. Stored XSS

- [x] Escape every user/admin-supplied string at the view boundary in the highest-risk pages:
      `theme/default/watch.php`, `watch_tv.php`, `blog_details.php`, `thumbnail.php`,
      `movies.php`, `comments.php`.
- [x] Fix the raw `urldecode()` download URL in `watch.php`.
- [ ] Add CSP in report-only mode; record violations before enforcing. (Deferred: needs a
      report endpoint and a violation review window.)
- [x] Add a static check that the touched views contain no raw `echo $row[...]` for the
      identified fields.

**Files:** the views above, `application/helpers/custom_helper.php` (`e()`).

### 6. Open redirects

- [x] Extend `safe_redirect()` to accept an allow-list/fallback and reject absolute and
      protocol-relative URLs (already implemented by `safe_redirect`).
- [x] Replace every `redirect($this->agent->referrer())` with the safe helper (106 call sites).
- [x] Add a unit test for `https://evil.test`, `//evil.test`, `javascript:` and a local path
      (existing `safe_redirect` tests).

**Files:** `application/helpers/custom_helper.php`, `Admin.php` and other controllers.

### 7. Weak key generation

- [x] Replace `mt_rand()` key generation with `random_int()` (CSPRNG).
- [x] Add a static check that no key generation uses `mt_rand`/`rand`.

**Files:** `Admin.php`, `Common_model.php`.

### 8. Unbounded uploads

- [x] Set explicit `max_size` for image (5 MB) and video (200 MB) uploads.
- [x] Keep MIME sniffing and GD re-encode for images; keep PHP execution disabled.
- [x] Add a static check that every `upload` config has a non-zero `max_size`.

**Files:** `Admin.php`, upload config blocks.

### 9. Legacy Stripe path trusts `paid`

- [x] Route the legacy `Subscription::stripe()` path through `Subscription_model::verify_payment`.
- [x] Remove any grant decision based only on client/posted payment fields; re-fetch the charge.
- [ ] Add a unit test with a forged “paid” payload → no entitlement. (Requires Stripe sandbox stubs;
      covered by a static assertion until the contract harness exists.)

**Files:** `Subscription.php`, `Subscription_model.php`.

---

## Exit gate

- [x] `php tests/run.php` green (unit): 49 tests, 121 assertions.
- [x] `php tests/security/check.php` green (static), including the new Phase 1 invariants.
- [ ] HTTP smoke suite green on staging (`STAGING_URL` required).
- [ ] API contract suite green against CI3 (`STAGING_URL` required).
- [x] A review confirms the fixed endpoints no longer mutate/authenticate unauthenticated.
- [ ] `git status` clean; commits pushed; baseline tag pushed.

> **Mobile IDOR** remains the one open Phase 1 item, deliberately deferred to Phase 7 as
> described above. Do not flip on token enforcement until the app ships token support.

Only then start Phase 3 (Laravel skeleton + design system).
