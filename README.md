# Uplift

**Dependency upgrades with guardrails for Symfony projects.**

Uplift looks at what can move (PHP, Symfony, your own packages, every other package),
sizes each move (patch, minor, major), and does only what your policy allows. It
rewrites `composer.json` when a move goes past a constraint (`7.4.*` -> `8.0.*`,
`^0.3` -> `^0.4`), runs `composer update`, Rector if you have it, then your tests, and
writes a report. It never merges, pushes or deploys: it leaves a branch, a commit and a
report for a person, a sandbox or CI (see [What Uplift does, and what stays yours](#what-uplift-does-and-what-stays-yours)).

*Documentation française : [README.fr.md](README.fr.md)*

```bash
composer require acencyril/uplift-bundle
php bin/console uplift:plan
```

### A real run

From the [end-to-end workflow](.github/workflows/e2e.yml): a fresh Symfony 7.3 app,
`UPLIFT_SYMFONY=minor`, then `uplift:apply` and `uplift:adopt --apply`, with the real
Packagist and the app's own tests.

| | |
|---|---|
| Symfony | 7.3.11 → 7.4.21 |
| Packages updated | 40 |
| `composer.json` constraints rewritten | 11 (`7.3.*` → `7.4.*`) |
| Deprecations fixed by Rector | 1 (the old `Routing\Annotation\Route`, `RenameAttributeRector`) |
| New features adopted | 2 (`as-command`, `readonly-promotion`, opted in), tests passed after each |
| Tests | passed |

DoctrineBundle 3, DoctrineMigrationsBundle 4 and PHPUnit 12 were held back and the report
said why (they need a newer PHP than the project's 8.2).

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
| `UPLIFT_OWN_VENDOR` | vendors, comma-separated, or `none` | none | packages you publish yourself, e.g. `acme` (see [Your own packages](#your-own-packages)) |
| `UPLIFT_TEST_COMMAND` | a shell command | `vendor/bin/phpunit` | what says the upgrade works; empty: no tests |
| `UPLIFT_ADOPT`, `UPLIFT_ADOPT_SKIP`, `UPLIFT_ADOPT_OPT_IN` | see [New features](#new-features-not-just-deprecations) | `propose` | |
| `UPLIFT_HUMAN_VALIDATION` | `0`, `1` | `1` | written to `report.json` (`policy.human_validation`) for the job that runs Uplift: `1`, an upgrade waits for a person; `0`, your job may merge it. Uplift itself does nothing more with it (see [below](#what-uplift-does-and-what-stays-yours)) |
| `UPLIFT_SKIP` | `package@version`, comma-separated (`symfony@7.4.18` for the framework) | none | releases never to move to, e.g. one your deploy rolled back |

A level is the largest move done automatically. Anything larger is **proposed** in the
report and waits for a person. With the defaults, patches are applied and every minor
or major is proposed only. A wrong value (`UPLIFT_PHP=all`) is an error, never a
silent default.

### Your own packages

By default no vendor is "own": every package follows `UPLIFT_PACKAGES`. A company that
publishes its own packages (on Packagist, Private Packagist, Satis or a Git repository) sets
its Composer vendor, the part before the `/`:

```dotenv
UPLIFT_OWN_VENDOR=acme            # several: acme,acme-internal
```

Packages of `UPLIFT_OWN_VENDOR` move on their own **up to a minor**, whatever
`UPLIFT_PACKAGES` says (further if `UPLIFT_PACKAGES` allows more). Below 1.0 the minor
is the breaking level (`0.3` -> `0.4`), so your 0.x packages follow their releases
fully: you write both sides and release them together. From 1.0 on, a major is a
deliberate break of a stable API, so it waits for someone to read its upgrade notes.
Empty or `none`: no own vendor. Before 0.6 the default was `acencyril` (the author's
vendor); see [UPGRADE.md](UPGRADE.md).

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

### One step at a time

Uplift moves the way upgrade guides do: one step per run, for PHP, for Symfony and for
each package. The next step comes on the next run (next night, next month), and only
once this one has passed its tests, and has been validated when
[Rehearsal](https://github.com/acencyril/rehearsal-bundle) is in manual mode.

- **Majors, one per run.** Before a major move, a package goes to the latest minor of
  its current major: that is where every deprecation of the next major shows. Only
  from there does it move to the latest release of the **next** major, never further.
  For Symfony, that is its LTS: a project on 6.2 goes to 6.4 first, then 7.x, then 8.x
  (`7.4` -> `8.1` is fine: 8.1 is a minor of the next major).
- **Below 1.0**, each minor is a line of its own: `0.3` -> `0.4` -> `0.5`.
- **PHP, one branch per run:** `8.1` -> `8.2` -> `8.3`, never `8.1` -> `8.4`. The
  ceiling (`UPLIFT_PHP_MAX`) still applies.

The policy still says how large this run's step may be; a step it does not allow is
proposed. The plan and the report show the whole way ahead, so you see how far behind
the project is:

```
symfony/*: 7.3.5 -> 7.4.8 now; then 8.1.2 in later runs
```

## The commands

### `uplift:plan`

Reads `composer.json`, `composer.lock`, `composer outdated --direct`, Packagist and
php.net, then lists every move with its decision:

| Decision | Meaning |
|---|---|
| `apply` | allowed by the policy: `uplift:apply` does it |
| `propose` | works, but goes past the policy: a person decides |
| `blocked` | cannot be done here; the reason says why |

For each of PHP, Symfony and every direct dependency there are at most three lines,
all within this run's step (see [One step at a time](#one-step-at-a-time)): the largest
allowed move, a larger one to propose, a still larger one that is blocked. Then the
way ahead: the steps left for later runs.

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
| `--rehearsal=FILE` | also add the run as one change to a [Rehearsal](https://github.com/acencyril/rehearsal-bundle) changes file, with a `summary` that Rehearsal 0.2+ shows as a card: the main move, packages moved (from composer.lock), constraints rewritten, tests after the move, what Rector rewrote, the moves refused by the policy and the way ahead. The tests are not run before the move, so the card has no "before" |
| `--report-dir=DIR` | where the reports go |
| `--outdated=FILE`, `--no-outdated` | a saved `composer outdated`, or none |
| `--no-rector` | skip Rector |
| `--adopt` | then look for the new features of the installed versions (see [New features](#new-features-not-just-deprecations)) |

Exit code 1 when composer failed, the tests failed or the run was refused; 0 otherwise
(including "disabled" and "nothing to do").

What happens next is yours: push the branch from CI, open a pull request, build a
sandbox, try the change in your admin with Rehearsal (see
[What Uplift does, and what stays yours](#what-uplift-does-and-what-stays-yours)).

## New features, not just deprecations

Moving versions and fixing deprecations keeps a site running. A release also brings
features the site could now use: code written by hand before the feature existed.
`uplift:adopt` finds it.

An **adoption** is one new feature of a package version (or of PHP): its package and
first version, a title and a short explanation (translations `adoption.<id>.title` and
`adoption.<id>.summary`, domain `uplift`, in English and French), a link to the upstream
documentation, a detector, and a Rector rule when one does the rewrite (otherwise the
change is manual). Only the adoptions the site's installed versions allow are looked
for (`composer.lock`, and for PHP the version the project is built for); the others
are listed as *later*.

Detection is static: the code is parsed (nikic/php-parser), never loaded or run. Each
candidate is reported as `file:line` with the line itself and what to use there.

| Adoption | Needs | Finds | Rewrite |
|---|---|---|---|
| `map-request` | `symfony/http-kernel` >= 6.3 | in controller actions, `json_decode($request->getContent())` and `$request->toArray()` (`#[MapRequestPayload]`), `$request->query->get('page')`, `->getInt('page')`… (`#[MapQueryParameter]`), `$request->query->all()` (`#[MapQueryString]`) | manual: the target class is yours to write |
| `as-command` | `symfony/console` >= 5.3 | commands declaring `protected static $defaultName` or `$defaultDescription` (deprecated since 6.1) without `#[AsCommand]` | Rector `CommandPropertyToAttributeRector` |
| `readonly-promotion` | PHP >= 8.1, **opt-in** | promoted, typed constructor properties never written again: private, or in a final class; not in Doctrine entities | Rector `ReadOnlyPropertyRector`, **experimental** |
| `lock-attribute` | to be confirmed | controllers and listeners calling `createLock()` then `acquire()`/`release()` | none, **experimental** |

`lock-attribute` is an example of an adoption for a feature that is **not confirmed**:
a lock attribute on controller actions (`#[Lock('invoice-{id}', blocking: true, ttl: 10)]`
is the idea). Symfony 7.4 has no such attribute; its package, version and API are
placeholders until a release ships it. It is flagged *experimental*, and never
applied.

`readonly-promotion` is **experimental and off by default**. The class itself never
writes the property again, but code working through reflection can: the Serializer's
object normalizer, the Form component writing back to an object, Doctrine hydrating a
class that is not mapped as an entity, a "wither" that clones then sets through
reflection. On a readonly property each of them throws *Cannot modify readonly
property* at run time, in code the static scan does not see. Opt in with
`UPLIFT_ADOPT_OPT_IN=readonly-promotion` when your tests cover those paths; it then
follows `UPLIFT_ADOPT` like the others, and a failing test puts the files back.

```bash
php bin/console uplift:adopt                      # list, with the candidate places
php bin/console uplift:adopt --apply              # rewrite what the policy allows
php bin/console uplift:adopt --apply --rehearsal=.rehearsal/changes.json --locale=fr
```

With `--apply` and `UPLIFT_ADOPT=apply`, each adoption that has a Rector rule is done
on its own: Rector runs on its candidate files only (a configuration written for the
run, Rector 1.0 or later), then `UPLIFT_TEST_COMMAND`. When the tests fail (or Rector
does), its files are put back as they were, and the next adoption is tried. The result
of each one (`applied`, `reverted`, `rector_failed`, `unchanged`, `untested`) goes into
the report.

| Option | |
|---|---|
| `--apply` | rewrite with Rector, then the tests decide |
| `--path=DIR` | what to scan (repeatable; default: the `adopt_paths` setting, `src`) |
| `--rehearsal=FILE` | one [Rehearsal](https://github.com/acencyril/rehearsal-bundle) change per adoption (`uplift-adopt-<id>`), to validate or reject each on its own; the adoptions are also listed on the summary card of the latest upgrade change |
| `--report-dir=DIR` | where the reports go |
| `--locale=en\|fr` | the language of the titles and explanations |

The adoptions are written into the same `report.json` (key `adoptions`) and
`report.md` (section "New features") as `uplift:apply`.

### The policy

| Variable | Values | Default | |
|---|---|---|---|
| `UPLIFT_ADOPT` | `off`, `propose`, `apply` | `propose` | `off`: not looked for; `propose`: listed only; `apply`: `--apply` rewrites those with a Rector rule |
| `UPLIFT_ADOPT_SKIP` | adoption ids, comma-separated | none | adoptions you do not want, e.g. `as-command` |
| `UPLIFT_ADOPT_OPT_IN` | adoption ids, comma-separated | none | adoptions that are off by default and that you want: `readonly-promotion` |

Manual adoptions are only ever proposed, and so are experimental ones, except an
opt-in adoption you opted in to.

### In the monthly run

`uplift:apply --adopt` looks for the new features right after the version move: one
run moves versions, fixes deprecations (Rector with your `rector.php`) and proposes the
new features. Adoptions are rewritten only after a move whose tests passed (and
`UPLIFT_ADOPT=apply`); with `--commit`, they get their own commit. When nothing moved,
they are proposed only; when the move failed, they are not looked for.

### Your own adoptions

Implement `Acencyril\UpliftBundle\Adopt\Adoption` (one class per feature). In an
application with autoconfiguration, the class is tagged `uplift.adoption` on its own;
in a bundle, tag the service. Put its texts in `translations/uplift.<locale>.*`:

```php
final class MyAdoption implements Adoption
{
    public function id(): string { return 'my-feature'; }
    public function package(): string { return 'vendor/package'; }
    public function minVersion(): string { return '2.1'; }
    public function docs(): string { return 'https://…'; }
    public function rector(): ?string { return null; }        // or a Rector rule class
    public function experimental(): bool { return false; }
    public function detect(SourceFile $file): array { /* $file->ast(), $file->candidate($node, 'hint') */ }
}
```

## Installation and configuration

```bash
composer require acencyril/uplift-bundle
```

### With Symfony Flex

Uplift's recipe is in [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib).
Flex applies a contrib recipe only when the project allows it: it asks during
`composer require`, or allow them beforehand with
`composer config extra.symfony.allow-contrib true`. The recipe registers the bundle, writes
`config/packages/uplift.yaml` and adds the `UPLIFT_*` variables to `.env` with the safe
defaults.

> The recipe published there today is the one of Uplift 0.1: it writes
> `UPLIFT_OWN_VENDOR=acencyril` and lacks the newer variables. The 0.6 recipe is in this
> repository ([`recipe-contrib/`](recipe-contrib/acencyril/uplift-bundle/0.6)) and waits to
> be merged there. Until then, after a Flex install, set `UPLIFT_OWN_VENDOR` to your own
> vendor or leave it empty.

### By hand (no Flex, or contrib recipes not allowed)

Three things, the same the recipe does:

```php
// config/bundles.php
Acencyril\UpliftBundle\UpliftBundle::class => ['all' => true],
```

```yaml
# config/packages/uplift.yaml: only where things are; what may move is the policy
uplift:
    report_dir: '%kernel.project_dir%/var/uplift'
    composer: 'composer'                          # the composer binary
    project_dir: '%kernel.project_dir%'           # where composer.json is
    packagist_url: 'https://repo.packagist.org'   # see "Private packages"
    php_releases_url: 'https://www.php.net'
    adopt_paths:                                  # where uplift:adopt looks
        - 'src'
```

Every key is optional (the values above are the defaults); the file may be just `uplift: ~`.

```dotenv
# .env: the safe defaults (uplift:apply changes nothing until UPLIFT_ENABLED=1)
UPLIFT_ENABLED=0
UPLIFT_PHP=patch
UPLIFT_SYMFONY=patch
UPLIFT_PACKAGES=patch
UPLIFT_PHP_MAX=
UPLIFT_OWN_VENDOR=
UPLIFT_TEST_COMMAND="vendor/bin/phpunit"
UPLIFT_ADOPT=propose
UPLIFT_ADOPT_SKIP=
UPLIFT_ADOPT_OPT_IN=
UPLIFT_SKIP=
UPLIFT_HUMAN_VALIDATION=1
```

Set the values that differ per machine (`UPLIFT_ENABLED=1` where upgrades run) in the
server's or the CI's environment, or in `.env.local`.

## Private packages

Uplift reads two things, and only one of them goes through your Composer setup:

- **Composer itself.** `uplift:plan` and `uplift:apply` run `composer outdated --direct`
  and `composer update` in the project, so the project's `repositories` (Private
  Packagist, Satis, `vcs` repositories, path repositories) and Composer's own credentials
  apply as usual: `auth.json` in the project or in `COMPOSER_HOME`, or the `COMPOSER_AUTH`
  variable. One catch: the commands Uplift starts do not inherit the variables that
  Symfony's Dotenv loaded from your `.env*` files (on purpose, see the 0.1.1 entry of the
  [CHANGELOG](CHANGELOG.md)), so put `COMPOSER_AUTH` in the real environment (a CI secret,
  the server's environment) or use `auth.json`, not `.env.local`.
- **The list of releases.** To plan one step at a time and to check each release's PHP
  requirement, Uplift reads public Packagist's metadata (`packagist_url`,
  `/p2/<vendor>/<package>.json`) with no credentials. A private package is not there
  (404), so Uplift knows only the latest version `composer outdated` reports for it: it
  plans that version directly, without the intermediate steps, and cannot block it for its
  PHP requirement; `composer update` and your tests still decide. Do not pass
  `--no-outdated` on such projects: private packages would not be seen at all.

`packagist_url` replaces Packagist for every package and sends no credentials, so leave it
alone unless you run a public mirror with the same `/p2/` layout. And give your private
packages a vendor nobody uses on Packagist: if the same name exists there, Uplift reads the
public releases.

## What Uplift does, and what stays yours

What the bundle does, in the checkout it runs in:

1. plans the moves against the policy (`uplift:plan`);
2. with `UPLIFT_ENABLED=1`, `uplift:apply` rehearses the upgrade there: on a new branch
   with `--branch`, it rewrites `composer.json`, runs `composer update`, Rector, then your
   test command, and puts `composer.json` and `composer.lock` back when composer refuses;
3. commits the result on that branch with `--commit`;
4. writes `var/uplift/report.json` and `report.md` (the `status`: `passed`,
   `tests_failed`, `composer_failed`, `untested`, `nothing_to_do`, `disabled`, `refused`;
   and the policy, `human_validation` included);
5. with `--rehearsal=FILE`, records the run as a change for
   [Rehearsal](https://github.com/acencyril/rehearsal-bundle) to validate or reject in your
   admin.

What it does **not** do, and stays the project's job: push the branch, open the pull or
merge request, merge it, deploy, check the site afterwards, roll back. `UPLIFT_HUMAN_VALIDATION`
and `UPLIFT_SKIP` are there for that job: the first is copied into the report for your job
to read (merge on its own or wait for a person), the second is read by Uplift, which never
moves to a release listed there; your job adds a release to it after rolling it back.

Run it in a working copy made for it (a CI checkout, a separate clone on the server), never
in the directory your site is served from: it changes `vendor/`, `composer.json` and
`composer.lock` there.

### GitHub Actions: a branch and a pull request, once a month

```yaml
name: uplift
on:
    schedule:
        -
            cron: '17 4 1 * *'
    workflow_dispatch: ~
jobs:
    upgrade:
        runs-on: ubuntu-latest
        permissions:
            contents: write
            pull-requests: write
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
                id: apply
                run: |
                    branch="upgrade/$(date +%Y-%m)"
                    echo "branch=$branch" >> "$GITHUB_OUTPUT"
                    git config user.name "uplift"
                    git config user.email "uplift@users.noreply.github.com"
                    php bin/console uplift:apply --branch="$branch" --commit --adopt
            -
                # Also after failed tests (uplift:apply exits 1, the job stays red): the
                # branch and its report are there for a person to read. A failed push
                # fails the job. The pull request is opened only when the tests passed.
                if: ${{ !cancelled() }}
                env:
                    BRANCH: ${{ steps.apply.outputs.branch }}
                    GH_TOKEN: ${{ github.token }}
                run: |
                    if ! git show-ref --verify --quiet "refs/heads/$BRANCH"; then
                        echo "No $BRANCH branch: nothing moved, nothing to push."; exit 0
                    fi
                    git push origin "$BRANCH"
                    status=$(php -r 'echo json_decode(file_get_contents("var/uplift/report.json"))->status;')
                    if [ "$status" = passed ]; then
                        gh pr create --head "$BRANCH" --title "Uplift: $BRANCH" --body-file var/uplift/report.md
                    fi
```

The branch exists only when something moved. A branch of the same name already on the
remote (a second run in the month) makes the push fail, on purpose: merge or delete it
first. `gh pr create` needs "Allow GitHub Actions to create and approve pull requests" in
the repository's settings (Actions → General); and a pull request opened with
`github.token` does not start your other workflows: use a personal access token or a GitHub
App token if your tests must run on it.

### GitLab CI: a branch and a merge request

In a scheduled pipeline, with a project access token (scope `write_repository`) in the
masked variable `UPLIFT_PUSH_TOKEN`:

```yaml
uplift:
    image: your-php-image:8.3        # your PHP, with git and composer
    rules:
        -
            if: '$CI_PIPELINE_SOURCE == "schedule"'
    variables:
        UPLIFT_ENABLED: '1'
    script:
        - git config user.name "uplift" && git config user.email "uplift@example.com"
        - git checkout -B "$CI_DEFAULT_BRANCH" "origin/$CI_DEFAULT_BRANCH"
        - composer install --no-interaction
        - BRANCH="upgrade/$(date +%Y-%m)"
        - php bin/console uplift:apply --branch="$BRANCH" --commit || true
        - git show-ref --verify --quiet "refs/heads/$BRANCH" || { echo "nothing moved"; exit 0; }
        - STATUS=$(php -r 'echo json_decode(file_get_contents("var/uplift/report.json"))->status;')
        - >
            git push "https://uplift:${UPLIFT_PUSH_TOKEN}@${CI_SERVER_HOST}/${CI_PROJECT_PATH}.git" "$BRANCH"
            -o merge_request.create -o merge_request.target="$CI_DEFAULT_BRANCH"
            -o merge_request.title="Uplift: $BRANCH ($STATUS)"
        - test "$STATUS" = passed
```

The merge request is opened whatever the tests said, with the status in its title, and the
job fails when they failed.

### A cron line on a server

In a clone kept for upgrades (here `/srv/uplift/app`, with `UPLIFT_ENABLED=1` in its
`.env.local`), on the first of the month at 4:17 (`%` is escaped in a crontab):

```cron
17 4 1 * * cd /srv/uplift/app && git fetch -q origin && git checkout -q main && git reset -q --hard origin/main && composer install -q --no-interaction && php bin/console uplift:apply --branch=upgrade/$(date +\%Y-\%m) --commit && git push -q origin HEAD
```

`git push origin HEAD` pushes the upgrade branch when one was made, and nothing otherwise
(HEAD is then `main`, already up to date). When the tests fail, nothing is pushed: the
branch and `var/uplift/report.md` stay in that clone, and cron mails the output.

### Merging without a person, and rolling back

Whether an upgrade whose tests passed may be merged without a person is your job's choice;
`UPLIFT_HUMAN_VALIDATION` lets each site state it where the job can read it
(`report.json`, `policy.human_validation`). A job that merges on its own typically:

1. runs `uplift:apply` on a branch from the deployed one, and merges it only when
   `status` is `passed` (never `untested`) and `human_validation` is false;
2. deploys as usual, checks the site answers, and when it does not, goes back to the
   previous release and reverts the upgrade commit;
3. adds each moved `package@version` (`result.applied` in `report.json`) to `UPLIFT_SKIP`, so the next run takes the next release instead.

Uplift's part is steps 1 (the upgrade and the report) and 3 (skipping what is listed); the
merge, the deploy, the check and the rollback are your deployment's. The author's own
servers do them with a shell job outside this bundle.

## Older projects

Uplift needs PHP 8.1 or later and Symfony 6.4 or later: Composer refuses to install it
on PHP 5 or 7, or next to Symfony 4 or 5. That is on purpose. Catching up several
majors is a project for a person: each major removes what the previous one deprecated,
and the code changes it takes are covered only partly by Rector.

The manual path, one major at a time, each with its own tests:

1. Symfony 4.4 -> 5.4: fix the deprecations 4.4 reports, raise PHP to what 5.4 needs
   (7.2.5 or later; 8.1 makes the next step easier), move to 5.4, fix its deprecations;
2. Symfony 5.4 -> 6.4, with PHP 8.1 or later;
3. then `composer require acencyril/uplift-bundle`.

From then on Uplift keeps the project moving, one step per run.

## What it does not do

- It moves direct dependencies. Transitive ones follow as far as the direct ones need
  (`--with-dependencies`); for a Symfony move, every locked `symfony/*` package on the
  old release line moves too, required directly or not.
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
