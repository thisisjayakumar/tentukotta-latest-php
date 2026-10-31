# Dependency and framework remediation (tracked, not executed)

Date: 2026-10-31
Owner: Platform maintainer (assign a named engineer before the next sprint)
Status: **Decision recorded — implementation tracked outside the security-refactor plan.**

## Why this exists

The application vendors its PHP dependencies under
`_public_html/application/third_party/` and runs CodeIgniter 3.1.13, which is
end-of-life. The security-refactor plan closes application-level issues first;
this document keeps the dependency risk visible and gives the next task an
explicit owner and decision.

## Inventory (verified from the vendored tree on 2026-10-31)

| Package | Current version | Location | Risk / minimum guidance |
|---|---|---|---|
| `codeigniter/framework` | 3.1.13 | `_public_html/system/` | EOL. No further security releases. Migrate to CI4 or another maintained framework. |
| `guzzlehttp/guzzle` | 6.5.3 | `application/third_party/google-api-client/vendor/` | CVE-2022-31042, CVE-2022-31043, CVE-2022-31090 (cookie/header leakage, improper header handling). Upgrade to ≥ 6.5.8 or 7.x after compatibility review. |
| `google/apiclient` | 2.4.1 | same | Pulls the vulnerable Guzzle tree. Upgrade together with Guzzle/`google/auth`. |
| `google/auth` | 1.8.0 | same | Older OAuth handling. Upgrade with `google/apiclient`. |
| `guzzlehttp/psr7` | 1.6.1 | same | CVE-2022-24775 (header parsing). Upgrade to ≥ 1.9.1 / 2.x. |
| `guzzlehttp/promises` | 1.3.1 | same | Upgrade with Guzzle. |
| `firebase/php-jwt` | 5.2.0 | same | Older JWT implementation; upgrade to a supported 6.x line after checking call sites. |
| `phpseclib/phpseclib` | 2.0.27 | same | CVE-2023-2753 / CVE-2023-2754 (RSA timing, AES-GCM). Upgrade to ≥ 2.0.40 or 3.x. |
| `monolog/monolog` | 2.0.2 | same | CVE-2022-31624 / CVE-2022-31626 in older 2.x lines. Upgrade to a patched 2.x/3.x. |
| `stripe/stripe-php` | 9.6.0 | `application/libraries/stripe/` | Below the current supported major. Track API-version compatibility before upgrading. |

Versions above were read from
`application/third_party/google-api-client/vendor/composer/installed.json`
and `application/libraries/stripe/lib/Stripe.php`.

## Decision

**Option (a): targeted upgrade of the vendored tree**, sequenced as follows:

1. Upgrade `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `guzzlehttp/promises`,
   `google/auth` and `google/apiclient` as one compatible set; re-run the
   Google login flow and the API smoke checks.
2. Upgrade `phpseclib` and `firebase/php-jwt`; verify the mobile login and
   token paths.
3. Upgrade `monolog` and `stripe-php`; verify logging and checkout.
4. Record the framework migration (CI3 → CI4) as a separate, scheduled
   project. Do not combine it with the dependency upgrade.

Until step 1 lands, the exposed Guzzle version is a tracked residual risk and
the guard test in `tests/security/check.php` stays `SKIP` rather than failing
every run. Flip it to a hard assertion when the upgrade task starts.

## Verification checklist for the upgrade

- [ ] `composer audit` (or `composer show --outdated`) on the vendored tree
- [ ] Google OAuth login and token refresh succeed
- [ ] `php tests/security/check.php` is green, then the Guzzle guard is un-skipped
- [ ] `tests/security/http_smoke.sh` against staging is green
- [ ] Mobile app login/purchase smoke test on a staging build
- [ ] Rollback artefact retained for one release cycle
