# Secret Rotation Runbook

> Run this **as soon as the security plan completes (after Task 1)**. These secrets are hardcoded in the codebase in plaintext and are currently readable by anyone who can reach `application/config/`, `application/controllers/rest_api/`, the git history, or the `db_backup/` dump.

**Scope:** all plaintext secrets embedded in the Pudhu Padam application. Values live in `application/config/database.php`, `application/config/config.php`, `application/config/rest.php`, and as `INSERT INTO config` rows. **Never commit new values** — enter them only on the server (or a local untracked `.env` file) and rotate the database/config values through the admin panel / a migration.

## Secrets inventory

| # | Secret | Where stored | Owner | Rotate? | Verify |
|---|--------|--------------|-------|---------|--------|
| 1 | **DB password** | `application/config/database.php` → `'password' => ...` | DevOps | Immediately | `mysql -u u473259675_pp_stream_1 -p -e 'SELECT 1'` |
| 2 | `mobile_apps_api_secret_key` | `config` table (row 142); shown in `Admin.php:2461` / `2777` and in REST responses | DevOps | Immediately | `SELECT value FROM config WHERE title='mobile_apps_api_secret_key'` |
| 3 | `cron_key` | `config` table; consumed by `Cron::image()` / `check_cron_key()` | DevOps | Immediately | fire `index.php?/cron/image/<newkey>` and confirm 200 |
| 4 | `google_client_id` / `google_client_secret` | `config` rows 66–67; also hardcoded in `google.php` library | DevOps | Immediately (secret is published in `config`) | OAuth consent screen still resolves the client |
| 5 | `paypal_client_id` / `paypal_secret` / `paypal_email` | `config` rows 201–206 | Owner | Immediately | Sandbox IPN test completes with `payment_status = Completed` |
| 6 | `stripe_secret_key` | `config` row 204; consumed by `Stripegateway::checkout()` | Owner | Immediately | Sandbox PaymentIntent creates and succeeds |
| 7 | `razorpay_key_id` / `razorpay_key_secret` | `config` rows 223–224 | Owner | Immediately | Sandbox signature verification passes |
| 8 | `encryption_key` | `config.php:232` → `'<redacted>'` | DevOps | Immediately | session content still encrypts/decrypts |
| 9 | `onesignal_api_keys` | `config` row 3274 | Owner | Immediately | test push reaches a test device |
| 10 | `tmbd_api_key` | `config` row 3506 | Owner | If stale | `Tmdb_model::get_movie_info()` returns status `success` |
| 11 | SMTP password / `system_email` credentials | `application/config/email.php` (CI3 `smtp_password`) | DevOps | Immediately | `php -r` testmail to `qa-verify@example.test` |

## How to rotate (repeat per secret)

1. Generate a new value locally: `openssl rand -hex 32` (arbitrary secrets); for the DB password use the hosting panel; for payment providers use each provider's dashboard.
2. Update the **application source of truth** in `application/config/database.php`, `config.php`, `rest.php`, or the `config` table — whichever currently holds the secret.
3. **Force a full password reset for all 123 users** if the DB password itself rotates (existing sessions are fine; MD5 hashes never need re-computation since we migrate on next login — but a compromised DB password means all stored MD5 hashes were potentially exfiltrated, so treat them as compromised):
   ```sql
   UPDATE user SET is_password_set = 0 WHERE is_password_set = 0;
   ```
   This marks unknown-password accounts to require a reset-on-first-login flow. For MD5-hashed accounts, the first successful login upgrades the hash to Argon2id (Task 6); force-reset via `complete_reset` email for any account whose email matches a known breach or whose last_login predates the rotation.
4. Verify. Record rotation date, old-value last-4 (never the full value), and verifier output in this doc.
5. Update any **external integrations** that hold the rotated secret: the Android app (API secret), OneSignal dashboard (push keys), Google OAuth console (client IDs), Stripe/Razorpay/PayPal webhooks.

## Post-rotation hygiene

- Run `git log -S '<old-secret-substring>'` to confirm no value is in history. If it is, rewrite history (`git filter-branch`/BFG) **off the public host**, then force-push from an offline clone.
- Re-run `SECURITY-AUDIT.md` checks that target hardcoded secrets.
- Rotate again on any suspected compromise or on the developer rotation schedule (quarterly minimum for payment secrets).
