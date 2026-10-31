# Security Check Harness

## Static assertions — `php tests/security/check.php`

Runs PHP 8.3–only assertions against the web root (`_public_html`). Exit code `0` = all pass, `1` = any fail.

Later tasks append assertions to the file, so the harness **grows with the code** and always fails-fast on regressions.

## HTTP smoke — `./tests/security/http_smoke.sh`

Requires `$STAGING_URL` (e.g. `https://staging.example.test`). Probes live endpoints and asserts status codes. Used only after a staging deploy; never against a public URL.

## Usage

```bash
# Static checks (always)
php tests/security/check.php

# HTTP smoke checks (staging only)
STAGING_URL=https://staging.example.test ./tests/security/http_smoke.sh
```

## Notes

- No Composer / PHPUnit dependency. Pure PHP 8.3.
- All paths are relative to the repo root (`tests/security/` sits outside `_public_html` to keep them off the web root).
