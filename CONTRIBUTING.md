# Contributing

## Set up

```bash
git clone https://github.com/acencyril/uplift-bundle
cd uplift-bundle
composer install
vendor/bin/phpunit
```

The tests use fixture `composer.json` / `composer.lock` files, fixed release lists and a fake
runner: no network. PHPUnit fails on warnings and notices (`phpunit.dist.xml`), on every PHP
line the CI runs (`.github/workflows/tests.yml`). The end-to-end workflow (`e2e.yml`) runs
Uplift in a real Symfony application with the real Packagist.

Every change comes with a test, a `CHANGELOG.md` entry under `[Unreleased]`, and, when it
asks something of the people who upgrade, an `UPGRADE.md` entry. Pushing a new version
header to `CHANGELOG.md` on `main` tags the release (`release.yml`).

## The Flex recipe

Symfony Flex applies recipes only from [symfony/recipes](https://github.com/symfony/recipes)
and [symfony/recipes-contrib](https://github.com/symfony/recipes-contrib), never from the
package itself. The recipe is kept here in the exact recipes-contrib layout,
`recipe-contrib/acencyril/uplift-bundle/<version>/`, and `tests/RecipeTest.php` checks that
it registers the bundle, that its configuration is valid and that its `.env` lines are the
safe defaults of `Policy`.

When the recipe changes (a new variable, a new default):

1. update the recipe here, in a folder named after the first version that needs it
   (`0.6`, `0.7`…), and keep the test green;
2. once that version is released, fork symfony/recipes-contrib, copy
   `recipe-contrib/acencyril/uplift-bundle/<version>/` to the same path in the fork, next to
   the versions already there (Flex picks the highest recipe version not above the installed
   package version, so earlier folders stay for earlier releases);
3. open a pull request on symfony/recipes-contrib titled
   "Add recipe for acencyril/uplift-bundle <version>"; its CI checks the layout and the
   manifest, and its bot posts how to try the recipe before it is merged;
4. once it is merged, update the "With Symfony Flex" paragraph of the READMEs.
