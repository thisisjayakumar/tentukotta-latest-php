# Test suites

All suites are dependency-light and run against a local or staging instance. None of them use production accounts or real user data.

| Suite | Command | Needs | What it proves |
|---|---|---|---|
| Static security | `php tests/security/check.php` | PHP 8.3 | Configuration, routing, escaping, upload and auth invariants hold in source. |
| Unit | `php tests/run.php` | PHP 8.3 | `Password`, `env()`, `safe_redirect()`, API-key comparison, `Safe_zip`, SSRF URL rules. |
| HTTP smoke | `STAGING_URL=… ./tests/security/http_smoke.sh` | A running instance | Admin gate, REST auth, security headers. |
| Integration contract | `STAGING_URL=… ./tests/integration/api_contract.sh` | Running instance + MySQL `.env` | Public pages, denied paths, admin gate, REST/legacy API contract. |
| Browser smoke | `npx playwright test --config=tests/browser/playwright.config.js` | Node + Chrome | Home, movies, search, login and anonymous admin refusal render correctly. |

## Unit suite

```bash
php tests/run.php            # all tests
php tests/run.php password   # filter by test name
```

`tests/bootstrap.php` defines the CodeIgniter constants and stubs `redirect()`/`base_url()`; `redirect()` records its target in `$GLOBALS['__redirect_target']` instead of exiting. Tests live in `tests/unit/*_test.php`.

## Integration suite

```bash
STAGING_URL=http://localhost:8080 ./tests/integration/api_contract.sh
```

The script reads DB credentials from the repository-root `.env` when present. Without them, the authenticated API cases are reported as `SKIP` rather than failing.

## Browser suite

```bash
npm install            # once; installs @playwright/test from package-lock.json
npm run test:browser   # or: npx playwright test --config=tests/browser/playwright.config.js
```

The config uses the system Chrome (`CHROME_PATH`, default `/usr/bin/google-chrome`), so `npx playwright install` is not required. Override the target with `STAGING_URL`.

## Notes

- `tests/security/` and `tests/integration/` live outside the web root.
- `tests/unit/` never opens a database connection.
- The test runner has no Composer dependency; Playwright is a dev-only npm dependency.
