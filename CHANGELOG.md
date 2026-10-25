# Changelog

All notable changes to Uplift.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [Unreleased]

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

[Unreleased]: https://github.com/acencyril/uplift-bundle/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/acencyril/uplift-bundle/releases/tag/v0.1.0
