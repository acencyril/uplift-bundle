#!/usr/bin/env bash
# One stage of the end-to-end run (see ../workflows/e2e.yml): a fresh Symfony app one
# minor behind, with Uplift, Rehearsal and Echo installed from the checked-out repos.
# Failures are printed as ::error:: annotations, facts as ::notice:: annotations.
set -uo pipefail

E2E="$(cd "$(dirname "$0")" && pwd)"
WS="${GITHUB_WORKSPACE:-$(pwd)}"
APP="$WS/app"
LOGS="$WS/e2e-logs"
mkdir -p "$LOGS"

escape() { local s="$1"; s=${s//'%'/'%25'}; s=${s//$'\r'/'%0D'}; s=${s//$'\n'/'%0A'}; printf '%s' "$s"; }
fail() { echo "::error title=$1::$(escape "$2")"; exit 1; }

# run NAME CMD...: runs CMD in the app, output in $LOGS/NAME.log (and on screen); a failure
# becomes an error annotation with the end of the output.
run() {
    local name=$1; shift
    echo "+ $*"
    (cd "$APP" && "$@") >"$LOGS/$name.log" 2>&1
    local rc=$?
    cat "$LOGS/$name.log"
    if [ $rc -ne 0 ]; then
        fail "$name failed" "$* (exit $rc)"$'\n'"$(tail -c 4000 "$LOGS/$name.log")"
    fi
}
# soft NAME CMD...: the same, but a failure is left to check.php, which says what went wrong.
soft() {
    local name=$1; shift
    echo "+ $*"
    (cd "$APP" && "$@") >"$LOGS/$name.log" 2>&1
    local rc=$?
    cat "$LOGS/$name.log"
    if [ $rc -ne 0 ]; then
        echo "::warning title=$name exit $rc::$(escape "$(tail -c 3000 "$LOGS/$name.log")")"
    fi
}
check() { php "$E2E/check.php" "$1" "$APP" "$LOGS" || exit 1; }
version() { grep -m1 -oE '^## \[[0-9]+\.[0-9]+\.[0-9]+\]' "$WS/$1/CHANGELOG.md" | tr -dc '0-9.'; }

case "${1:-}" in
setup)
    # Symfony 7.3 is past its end of life: Composer blocks its releases with unfixed advisories.
    # Allowed while the app is built (it is meant to be behind); Uplift's own composer runs
    # later keep Composer's default.
    export COMPOSER_NO_SECURITY_BLOCKING=1
    (cd "$WS" && composer create-project symfony/skeleton:"7.3.*" app --no-interaction --no-progress) >"$LOGS/create.log" 2>&1 \
        || fail "create-project failed" "$(tail -c 4000 "$LOGS/create.log")"
    run docker-off composer config extra.symfony.docker false
    run require composer require --no-progress symfony/twig-bundle symfony/security-bundle symfony/mailer symfony/translation symfony/orm-pack
    run require-dev composer require --dev --no-progress phpunit/phpunit:^11.5 symfony/browser-kit symfony/css-selector rector/rector
    for b in uplift rehearsal echo; do
        v=$(version "$b-bundle")
        run "repo-$b" composer config "repositories.$b" "{\"type\":\"path\",\"url\":\"../$b-bundle\",\"options\":{\"versions\":{\"acencyril/$b-bundle\":\"$v\"}}}"
    done
    run require-bundles composer require --no-progress acencyril/uplift-bundle:^0.1 acencyril/rehearsal-bundle:^0.1 acencyril/echo-bundle:^0.1
    # the configuration the bundles' Flex recipes would write
    cp "$WS/uplift-bundle/recipe/config/packages/uplift.yaml" "$WS/rehearsal-bundle/recipe/config/packages/rehearsal.yaml" \
       "$WS/echo-bundle/recipe/config/packages/echo.yaml" "$APP/config/packages/"
    mkdir -p "$APP/config/routes" && cp "$WS/rehearsal-bundle/recipe/config/routes/rehearsal.yaml" "$APP/config/routes/"
    php "$E2E/setup.php" "$APP" "$WS" || fail "setup.php failed" "see the log"
    cp -r "$E2E/app/." "$APP/"
    run cache-clear php bin/console cache:clear
    run lint-container php bin/console lint:container
    run schema php bin/console doctrine:schema:update --force
    run git-init sh -c 'git init -q && git add -A && git -c user.name=e2e -c user.email=e2e@example.com commit -qm "e2e app" && git log --oneline'
    unset COMPOSER_NO_SECURITY_BLOCKING
    cp "$APP/composer.json" "$LOGS/before.composer.json"
    cp "$APP/composer.lock" "$LOGS/before.composer.lock"
    rm -rf "$APP/var/cache/test"  # deprecations are triggered when the container is built
    run tests-before vendor/bin/phpunit --testsuite app
    run deprecations-before php bin/console debug:container --deprecations
    check before
    ;;
plan)
    run plan php bin/console uplift:plan --format=json
    check plan
    ;;
apply)
    soft apply php bin/console uplift:apply --rehearsal=.rehearsal/changes.json -v
    (cd "$APP" && git diff --stat -- src && git diff -- src) >"$LOGS/rector-diff.log" 2>&1
    check apply
    (cd "$APP" && git add -A && git -c user.name=e2e -c user.email=e2e@example.com commit -qm "uplift:apply") >/dev/null 2>&1
    rm -rf "$APP/var/cache/test"
    run tests-after vendor/bin/phpunit --testsuite app
    run deprecations-after php bin/console debug:container --deprecations
    check after
    ;;
adopt)
    run adopt php bin/console uplift:adopt
    check adopt
    run adopt-apply env UPLIFT_ADOPT=apply php bin/console uplift:adopt --apply --rehearsal=.rehearsal/changes.json
    (cd "$APP" && git diff -- src) >"$LOGS/adopt-diff.log" 2>&1
    check adopt-apply
    ;;
rehearsal)
    run rehearsal vendor/bin/phpunit --testsuite e2e
    run rehearsal-status php bin/console rehearsal:status --json
    check rehearsal
    ;;
echo)
    run echo-demo php bin/console app:echo-demo
    run echo-digest php bin/console echo:digest --dry-run --day="$(php -r 'echo date("Y-m-d");')"
    check echo
    ;;
*)
    fail "run.sh" "unknown stage ${1:-}"
    ;;
esac
