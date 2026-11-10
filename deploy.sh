#!/usr/bin/env bash
# ============================================================
# Tunekotta Site - Deployment Bundle Builder
# ============================================================
# Builds a clean, upload-ready zip of the CodeIgniter web root
# (_public_html) for Hostinger / shared hosting.
#
# This script lives at the PROJECT ROOT, next to _public_html,
# so it is never bundled into the zip nor exposed on the web.
#
# Usage:
#   ./deploy.sh                  Build the zip in ./builds/
#   ./deploy.sh --init-env       Create .env from .env.example if missing
#   ./deploy.sh --upload=USER@HOST:/remote/public_html
#                                Build, then scp the zip to the server
#   ./deploy.sh --help
# ============================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEBROOT="${SCRIPT_DIR}/_public_html"
BUILD_DIR="${SCRIPT_DIR}/builds"
STAMP="$(date +%Y%m%d-%H%M%S)"
ZIP_NAME="tunekotta-${STAMP}.zip"
ZIP_PATH="${BUILD_DIR}/${ZIP_NAME}"

INIT_ENV=0
UPLOAD_TARGET=""

usage() {
  cat <<EOF
Usage: $0 [options]

Options:
  --init-env                 Create .env from .env.example (if missing)
  --upload=USER@HOST:PATH    scp the built zip to the server after building
  -h, --help                 Show this help

Examples:
  $0
  $0 --init-env
  $0 --upload=u123456@185.1.2.3:/home/u123456/domains/example.com/public_html
EOF
}

for arg in "$@"; do
  case "$arg" in
    --init-env) INIT_ENV=1 ;;
    --upload=*) UPLOAD_TARGET="${arg#*=}" ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown argument: $arg" >&2; usage; exit 1 ;;
  esac
done

# --- Validate project layout --------------------------------
if [ ! -d "${WEBROOT}" ]; then
  echo "Error: web root not found: ${WEBROOT}" >&2
  exit 1
fi
if [ ! -f "${WEBROOT}/index.php" ]; then
  echo "Error: ${WEBROOT}/index.php missing - is this the right project?" >&2
  exit 1
fi

# --- Optional: scaffold .env --------------------------------
if [ "${INIT_ENV}" -eq 1 ]; then
  if [ -f "${SCRIPT_DIR}/.env" ]; then
    echo " .env already exists - leaving it untouched."
  elif [ -f "${SCRIPT_DIR}/.env.example" ]; then
    cp "${SCRIPT_DIR}/.env.example" "${SCRIPT_DIR}/.env"
    chmod 600 "${SCRIPT_DIR}/.env"
    echo " Created .env from .env.example - fill in your real values."
  else
    echo "Error: .env.example not found, cannot scaffold .env" >&2
    exit 1
  fi
fi

# --- Build --------------------------------------------------
mkdir -p "${BUILD_DIR}"

echo "Building deploy bundle from: ${WEBROOT}"
echo "Output:                      ${ZIP_PATH}"

cd "${WEBROOT}"

# Exclusions:
#   .git*        - repository metadata (must never be deployed)
#   .env         - local secrets (created on the server instead)
#   *.zip        - previous bundles
#   db_backup/   - database dumps (never deploy)
zip -r -q "${ZIP_PATH}" . \
  -x ".git/*" \
  -x ".gitignore" \
  -x ".env" \
  -x "*.zip" \
  -x "db_backup/*" \
  -x "application/logs/*.php" \
  -x "application/cache/*" \
  -x "uploads/temp/*"

# Keep the runtime dirs present but empty/writable on the server.
# (Zip cannot store empty dirs when contents are excluded, so re-add stubs.)
cd "${WEBROOT}"
zip -q "${ZIP_PATH}" \
  application/cache/index.html \
  application/logs/index.html \
  uploads/index.html 2>/dev/null || true

# Ship the env template so the server admin can `cp .env.example .env`.
# .env.example carries no secrets; the real .env stays off the bundle.
if [ -f "${SCRIPT_DIR}/.env.example" ]; then
  cp "${SCRIPT_DIR}/.env.example" "${WEBROOT}/.env.example"
  zip -q "${ZIP_PATH}" .env.example
  rm -f "${WEBROOT}/.env.example"
fi

echo ""
echo "Done."
echo "  File: $(basename "${ZIP_PATH}")"
echo "  Size: $(du -h "${ZIP_PATH}" | awk '{print $1}')"
echo "  Path: ${ZIP_PATH}"

# --- Safety check -------------------------------------------
if unzip -l "${ZIP_PATH}" | grep -qE '(^|/)\.git/|(^|/)\.env$'; then
  echo "ERROR: bundle contains .git or .env - aborting." >&2
  rm -f "${ZIP_PATH}"
  exit 1
fi
echo "  Check: no .git / .env included."

# --- Optional upload ----------------------------------------
if [ -n "${UPLOAD_TARGET}" ]; then
  echo ""
  echo "Uploading to: ${UPLOAD_TARGET}"
  if ! command -v scp >/dev/null 2>&1; then
    echo "Error: scp not found (install openssh-client)." >&2
    exit 1
  fi
  scp "${ZIP_PATH}" "${UPLOAD_TARGET}/"
  echo "Upload complete."
fi

cat <<EOF

Next steps on Hostinger (hPanel -> File Manager):
  1. Upload/extract ${ZIP_NAME} into public_html
  2. Create MySQL DB + user in hPanel -> Databases
  3. Create .env in public_html (or the repo root above it) with:
       CI_ENV=production
       BASE_URL=https://yourdomain.com/
       DB_HOSTNAME=localhost
       DB_DATABASE=...   DB_USERNAME=...   DB_PASSWORD=...
       ENCRYPTION_KEY=<32+ hex chars>
  4. Make these writable (755):
       application/cache  application/logs  uploads
  5. Delete the zip after extraction.
EOF
