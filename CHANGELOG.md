# Changelog

All notable changes to Uplift.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [Unreleased]

## [0.4.0] — 2026-10-29

### Added
- `UPLIFT_SKIP`: releases never to move to (`package@version`, `symfony@…` for the
  framework), e.g. one that was shipped and rolled back. The release below is taken
  instead when the level allows it; a newer one is tried again. Listed in the report's
  policy and in the recipe.
- README: shipping without a person (Rehearsal's `validation: auto`) and rolling back.

## [0.3.1] — 2026-10-26

### Changed
- **`readonly-promotion` is experimental and off by default** (behaviour change): making a
  promoted property readonly is correct for the class itself, but code that writes
  properties through reflection (the Serializer's object normalizer, Form, Doctrine
  hydrating a non-entity class) then fails at run time, where the scan cannot see it. It
  is reported as skipped until listed in the new `UPLIFT_ADOPT_OPT_IN`
  (`UPLIFT_ADOPT_OPT_IN=readonly-promotion`); opted in, it follows `UPLIFT_ADOPT` as
  before. Adoptions can declare themselves opt-in with the `OptIn` marker interface. The
  Flex recipe in this repository lists the new variable.
- e2e: opts in to `readonly-promotion`, and checks that every adoption decided `apply` was
  applied.

### Fixed
- README, CI example: the push no longer ends with `|| true`, so a failed push fails the
  job. It pushes the upgrade branch by name, only when it exists, and also runs after
  failed tests (`!cancelled()` instead of `always()`).

### Added
- README: the figures of a real run (the e2e workflow) near the top.

## [0.3.0] — 2026-10-26

### Added
- **A summary for Rehearsal's card** (acencyril/rehearsal-bundle 0.2+): the change
  written by `--rehearsal` carries a `summary` with the main move (Symfony, from and to
  as locked), the PHP move, the packages moved, the composer.json constraints rewritten,
  the tests after the move, what Rector rewrote (rules and files), the moves refused by
  the policy with their reason, and the way ahead. What Uplift does not measure is left
  out: the tests are not run before the move, so there is no "before".
- `uplift:adopt --rehearsal` (and `uplift:apply --adopt`) also lists the adoptions, with
  their outcome and places, on the summary of the latest upgrade change.
- `report.json`: `result.lock_changes` (each package whose locked version changed) and
  `result.rector` (the rules applied and the files Rector changed, read from its output).
- e2e: the Rehearsal page shows the card with the run's real versions and tests.

## [0.2.1] — 2026-10-26

### Fixed
- Reports state the right Uplift version (0.2.0 still wrote 0.1.1).

## [0.2.0] — 2026-10-26

### Changed
- **One step at a time** (behaviour change): each run looks at the next step only, for
  PHP, for Symfony and for every package, the way upgrade guides go. With
  `UPLIFT_SYMFONY=major`, a project on Symfony 5.4 or 7.3 used to be moved straight to
  the newest 8.x.
  - Before a major move, a package goes to the latest minor of its current major
    (Symfony 6.2 -> 6.4, the LTS, where every deprecation of 7.0 shows); from there, to
    the latest release of the next major only (6.4 -> 7.x, never 8.x; 7.4 -> 8.1 is
    fine). Below 1.0, each minor is its own step (0.3 -> 0.4 -> 0.5).
  - PHP moves one branch per run (8.1 -> 8.2, never 8.1 -> 8.4); `UPLIFT_PHP_MAX` still
    applies.
  - The policy still decides how far this run's step goes (`apply`, `propose`,
    `blocked`); moves beyond the step are no longer listed as proposed or blocked.

### Added
- The way ahead: each move lists the steps left for later runs (`later` in the JSON),
  and the plan, `report.md` ("The way ahead"), `report.json` (`paths`) and the Rehearsal
  change show them: `symfony/*: 7.3.5 -> 7.4.8 now; then 8.1.2 in later runs`.
- README: "One step at a time", and "Older projects" (PHP 5 / 7, Symfony 4 / 5): why
  Uplift does not install there, and the manual path to Symfony 6.4.

## [0.1.1] — 2026-10-26

### Added
- An end-to-end workflow (`e2e`): a fresh Symfony 7.3 application with Uplift,
  Rehearsal and Echo, on the real Packagist and php.net; `uplift:plan`, `uplift:apply`
  (7.3 -> 7.4, the tests decide), `uplift:adopt` with real Rector, then the Rehearsal
  admin page and Echo's digest. The Rector rules of `as-command`
  (`CommandPropertyToAttributeRector`) and `readonly-promotion`
  (`ReadOnlyPropertyRector`) are checked there: both rewrite the code and the tests pass.

### Fixed
- The commands Uplift runs (composer, Rector, the test command) no longer inherit what
  the application's `.env` files loaded into the console process. Run from
  `bin/console`, `APP_ENV=dev` reached the test command, whose Dotenv loaded `.env` over
  PHPUnit's `APP_ENV=test`: functional tests booted the dev kernel and failed ("the
  framework.test config is not set"), so every upgrade and every adoption was reported
  as failing its tests and put back. Found by an end-to-end run on a real Symfony 7.3
  application.
- A Symfony move now also updates the Symfony components the project does not require
  itself but has locked on the same release line (`symfony/doctrine-bridge` through
  DoctrineBundle, `symfony/http-client`, `symfony/stopwatch`…). With
  `--with-dependencies` only, they stayed on 7.3 next to a 7.4 framework.
- `report.json` and the Rehearsal changes file are written even when a command's output
  is not valid UTF-8 (the invalid bytes are replaced); `json_encode()` used to fail and
  leave an empty report.
- When the project's Rector fails, `uplift:apply` says so in its notes (and the
  report), next to the result of the tests: it used to end on "tests passed" alone.

## [0.1.0] — 2026-10-25

First release.

### Added
- `uplift:plan`: reads `composer.json`, `composer.lock`, `composer outdated --direct`,
  Packagist and php.net, and lists every move of PHP, of the Symfony packages held to
  the framework's `X.Y.*` (as one line), of your own packages and of the others,
  sized patch / minor / major, with its decision (`apply`, `propose`, `blocked`) and
  the reason. Table, Markdown or JSON.
- `uplift:apply`: does the moves the policy allows. Rewrites the constraints a move
  goes past, in their own form (`7.4.*` -> `8.0.*`, `^0.3` -> `^0.4`, OR lists
  extended), `extra.symfony.require`, `config.platform.php` and the `require.php`
  floor, changing those values only. Then `composer update` for the moved packages
  pinned to their targets, Rector when the project has it, the test command. Puts
  `composer.json` and `composer.lock` back if composer refuses, and retries the moves
  that keep their constraints. Optional branch and commit; never pushes or deploys.
- The policy from the environment: `UPLIFT_ENABLED`, `UPLIFT_PHP`, `UPLIFT_SYMFONY`,
  `UPLIFT_PACKAGES` (`off|patch|minor|major`), `UPLIFT_PHP_MAX`, `UPLIFT_OWN_VENDOR`,
  `UPLIFT_TEST_COMMAND`. Safe defaults: off, patches only.
- A PHP ceiling (`UPLIFT_PHP_MAX`): PHP never moves above it, and any release that
  needs a PHP above it is blocked, with the reason in the report.
- Own vendors (default `acencyril`) move up to a minor on their own.
- Reports: `report.json` and `report.md`; `--rehearsal` adds the run as one change to
  a Rehearsal changes file.
- Packagist and php.net behind interfaces (`ReleaseSource`, `PhpReleaseSource`),
  commands behind `Runner`: tested with fixtures, no network.
- Flex recipe: the bundle, `config/packages/uplift.yaml`, the `UPLIFT_*` variables.
- New features, not just deprecations: `uplift:adopt` lists the adoptions the
  installed versions allow (one class per feature, tagged `uplift.adoption`, so a site
  or a bundle can add its own) and the places in the code doing the same thing by hand,
  found statically with nikic/php-parser (`file:line` and the line). Shipped:
  `map-request` (Symfony 6.3, `#[MapRequestPayload]`, `#[MapQueryString]`,
  `#[MapQueryParameter]`, manual), `as-command` (Symfony Console 5.3, `#[AsCommand]`,
  Rector), `readonly-promotion` (PHP 8.1, Rector), and `lock-attribute`, an
  experimental example for a feature not confirmed upstream, never applied. Titles and
  explanations in English and French (translation domain `uplift`).
- `uplift:adopt --apply`: with `UPLIFT_ADOPT=apply`, each adoption with a Rector rule is
  rewritten on its candidate files only, then the test command runs; its files are put
  back when the tests (or Rector) fail.
- The adoptions in `report.json` (`adoptions`) and `report.md` ("New features"), and
  with `--rehearsal` one Rehearsal change per adoption (`uplift-adopt-<id>`).
- `UPLIFT_ADOPT` (`off|propose|apply`, default `propose`) and `UPLIFT_ADOPT_SKIP`; the
  `adopt_paths` setting (default `src`).
- `uplift:apply --adopt`: looks for the new features after the version move, rewrites
  them only after a move whose tests passed, in a commit of their own with `--commit`.

[Unreleased]: https://github.com/acencyril/uplift-bundle/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/acencyril/uplift-bundle/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/acencyril/uplift-bundle/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/acencyril/uplift-bundle/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/acencyril/uplift-bundle/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/acencyril/uplift-bundle/releases/tag/v0.1.0
