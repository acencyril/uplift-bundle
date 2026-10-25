# Uplift

**Dependency upgrades with guardrails for Symfony projects.**

Uplift looks at what can move (PHP, Symfony, your own packages, every other package),
sizes each move (patch, minor, major), and does only what your policy allows. It
rewrites `composer.json` when a move goes past a constraint (`7.4.*` -> `8.0.*`,
`^0.3` -> `^0.4`), runs `composer update`, Rector if you have it, then your tests, and
writes a report. It never pushes and never deploys: it leaves a branch and a report for
a person, a sandbox or CI.

*Documentation française : [README.fr.md](README.fr.md)*

```bash
composer require acencyril/uplift-bundle
php bin/console uplift:plan
```

---

## The policy

Set in the environment (`.env`, the server, CI), so that each server decides without
a code change:

| Variable | Values | Default | |
|---|---|---|---|
| `UPLIFT_ENABLED` | `0`, `1` | `0` | at `0`, `uplift:apply` changes nothing (`uplift:plan` still plans) |
| `UPLIFT_PHP` | `off`, `patch`, `minor`, `major` | `patch` | the PHP the project targets |
| `UPLIFT_SYMFONY` | same | `patch` | the `symfony/*` packages held to the framework's `X.Y.*` |
| `UPLIFT_PACKAGES` | same | `patch` | every other package |
| `UPLIFT_PHP_MAX` | a branch, e.g. `8.1` | none | a hard ceiling for PHP (see below) |
| `UPLIFT_OWN_VENDOR` | vendors, comma-separated, or `none` | `acencyril` | packages you publish yourself |
| `UPLIFT_TEST_COMMAND` | a shell command | `vendor/bin/phpunit` | what says the upgrade works; empty: no tests |

A level is the largest move done automatically. Anything larger is **proposed** in the
report and waits for a person. With the defaults, patches are applied and every minor
or major is proposed only. A wrong value (`UPLIFT_PHP=all`) is an error, never a
silent default.

### Your own packages

Packages of `UPLIFT_OWN_VENDOR` move on their own **up to a minor**, whatever
`UPLIFT_PACKAGES` says (further if `UPLIFT_PACKAGES` allows more). Below 1.0 the minor
is the breaking level (`0.3` -> `0.4`), so your 0.x packages follow their releases
fully: you write both sides and release them together. From 1.0 on, a major is a
deliberate break of a stable API, so it waits for someone to read its upgrade notes.
Set `UPLIFT_OWN_VENDOR=none` to treat them like any other package.

### A PHP ceiling

Some servers cannot go above a PHP version: the operating system ships it, or the
application server supports nothing newer. Set `UPLIFT_PHP_MAX` to that branch:

- PHP never moves above it, whatever `UPLIFT_PHP` says;
- a release of any package that needs a PHP above the ceiling is **blocked**, and the
  report says so: `requires PHP >=8.2, above UPLIFT_PHP_MAX=8.1`.

Uplift also respects `config.platform.php`: packages are checked against the PHP the
project is built for, after the PHP move of the same run. A release that needs a newer
PHP than that (but under the ceiling) is blocked with
`requires PHP >=8.4, the project is on 8.3.27 (the proposed PHP move to 8.4 would allow it)`.

## The commands

### `uplift:plan`

Reads `composer.json`, `composer.lock`, `composer outdated --direct`, Packagist and
php.net, then lists every move with its decision:

| Decision | Meaning |
|---|---|
| `apply` | allowed by the policy: `uplift:apply` does it |
| `propose` | works, but goes past the policy: a person decides |
| `blocked` | cannot be done here; the reason says why |

For each of PHP, Symfony and every direct dependency there are at most three lines:
the largest allowed move, a larger one to propose, a still larger one that is blocked.

```bash
php bin/console uplift:plan                     # a table
php bin/console uplift:plan --format=markdown   # or json
php bin/console uplift:plan --outdated=outdated.json   # a saved composer outdated
```

### `uplift:apply`

Does the `apply` moves:

1. rewrites `composer.json` where a move goes past its constraint, keeping the way it
   was written (`7.4.*` -> `8.0.*`, `^0.3` -> `^0.4`, `~2.13.1` -> `~3.0.2`,
   `^6.4 || ^7.0` -> `^6.4 || ^7.0 || ^8.0`), the Symfony line (`extra.symfony.require`),
   and for PHP `config.platform.php` and the `require.php` floor. Only those values
   change: no reordering, no re-indenting;
2. runs `composer update` for the moved packages only, each pinned to its target
   (`--with vendor/package:1.2.3 --with-dependencies`). If composer cannot resolve
   every move together, both files are put back and the moves that keep their
   constraints are tried alone; if that fails too, nothing changes;
3. runs Rector (`vendor/bin/rector process`) when the project has `rector.php`
   (`--no-rector` to skip);
4. runs `UPLIFT_TEST_COMMAND`;
5. writes `var/uplift/report.json` and `var/uplift/report.md`.

```bash
php bin/console uplift:apply --branch=upgrade/2026-11 --commit --rehearsal=.rehearsal/changes.json
```

| Option | |
|---|---|
| `--branch=NAME` | create this branch and work on it |
| `--commit` | commit the result there (needs a clean working tree) |
| `--rehearsal=FILE` | also add the run as one change to a [Rehearsal](https://github.com/acencyril/rehearsal-bundle) changes file |
| `--report-dir=DIR` | where the reports go |
| `--outdated=FILE`, `--no-outdated` | a saved `composer outdated`, or none |
| `--no-rector` | skip Rector |

Exit code 1 when composer failed, the tests failed or the run was refused; 0 otherwise
(including "disabled" and "nothing to do").

What happens next is yours: push the branch from CI, open a pull request, build a
sandbox, try the change in your admin with Rehearsal.

## Configuration

Only where things are; what may move is the policy above.

```yaml
# config/packages/uplift.yaml
uplift:
    report_dir: '%kernel.project_dir%/var/uplift'
    composer: 'composer'
    project_dir: '%kernel.project_dir%'
    packagist_url: 'https://repo.packagist.org'
    php_releases_url: 'https://www.php.net'
```

With Symfony Flex the recipe registers the bundle, writes this file and adds the
`UPLIFT_*` variables to `.env` with the safe defaults. Without Flex:

```php
// config/bundles.php
Acencyril\UpliftBundle\UpliftBundle::class => ['all' => true],
```

## In CI

Uplift does not push: the workflow does. For example, once a month:

```yaml
name: uplift
on:
    schedule:
        -
            cron: '17 4 1 * *'
jobs:
    upgrade:
        runs-on: ubuntu-latest
        permissions:
            contents: write
        env:
            UPLIFT_ENABLED: '1'
        steps:
            -
                uses: actions/checkout@v4
            -
                uses: shivammathur/setup-php@v2
                with:
                    php-version: '8.3'
            -
                run: composer install --no-interaction
            -
                run: |
                    git config user.name "uplift"
                    git config user.email "uplift@users.noreply.github.com"
                    php bin/console uplift:apply --branch="upgrade/$(date +%Y-%m)" --commit
            -
                if: always()
                run: git push origin HEAD || true
```

## What it does not do

- It moves direct dependencies. Transitive ones follow as far as the direct ones need
  (`--with-dependencies`).
- It does not change your runtime: a PHP move changes `composer.json`; your Docker
  image or server must follow. The report says when the steps ran on another PHP.
- Constraints it cannot carry over (`>=1.0 <2.0`, branches) are left to you; the move
  is blocked with the reason.
- Pre-releases are never candidates.

## Tests

Packagist and php.net are behind two interfaces (`ReleaseSource`,
`PhpReleaseSource`), and commands behind `Runner`: the tests use fixture
`composer.json` / `composer.lock` files, fixed release lists and a fake runner. No
network.

```bash
vendor/bin/phpunit
```

## License

MIT
