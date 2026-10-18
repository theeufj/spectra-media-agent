# Keep Forge's deployment script in sync with this file. Forge expands the macros.
set -eo pipefail

$CREATE_RELEASE()

cd "$FORGE_RELEASE_DIRECTORY"
release_sha=$(git rev-parse HEAD)
if [ -n "${FORGE_DEPLOY_COMMIT:-}" ] && [ "$release_sha" != "$FORGE_DEPLOY_COMMIT" ]; then
    echo "Forge checked out a different commit than the CI deployment request; refusing deployment."
    exit 1
fi

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

frontend_config=$(mktemp)
trap 'rm -f "$frontend_config"' EXIT
$FORGE_PHP bin/frontend-build-config "$FORGE_RELEASE_DIRECTORY/.env" > "$frontend_config"
python3 bin/frontend-artifact install --sha "$release_sha" --public-config-file "$frontend_config" --root .

# PDF generation needs Puppeteer at runtime. Compilation happens only in CI.
npm ci --no-audit --no-fund

$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan storage:link
$FORGE_PHP artisan ziggy:generate
$FORGE_PHP artisan optimize

$ACTIVATE_RELEASE()

$FORGE_PHP artisan horizon:terminate
