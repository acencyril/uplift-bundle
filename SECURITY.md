# Security policy

Uplift runs commands in your project (composer, Rector, your test command) and rewrites
`composer.json`: a way to make it run something it should not, write outside the project, or
move a package the policy does not allow is a vulnerability.

## Reporting a vulnerability

Please **do not open a public issue** for a vulnerability.

Report it privately through GitHub: on this repository, open the **Security** tab and click
**Report a vulnerability**
(<https://github.com/acencyril/uplift-bundle/security/advisories/new>). If that button is not
there, write to **cdelage.dev@gmail.com** with the subject "Security", in English or French.

Include what you can: the affected version, the policy (`UPLIFT_*` variables), the steps to
reproduce, and what an attacker gains. You will get an answer within 72 hours. A confirmed
vulnerability is fixed within 7 days, and the fix is released before any public detail; the
advisory then credits you, unless you prefer otherwise. This is best effort: Uplift has a
single maintainer, and this policy says so rather than promise more.

The disclosure policy (timelines, safe harbour, scope):
<https://mirelabs.fr/en/responsible-disclosure>.

## Supported versions

Only the latest minor version receives security fixes.

| Version | Supported |
|---------|-----------|
| 0.6.x   | ✅        |
| < 0.6   | ❌        |

Uplift runs on PHP 8.1 to 8.5 and Symfony 6.4, 7.x and 8.x. On each push the tests run on
PHP 8.1 / Symfony 6.4, PHP 8.2 / Symfony 7.4, PHP 8.3 / Symfony 7.4, PHP 8.4 / Symfony 8 and
PHP 8.5 / Symfony 8, and `composer audit` checks the newest dependencies a project would get
(see `.github/workflows/tests.yml`).

## Versions

Uplift follows [SemVer](https://semver.org/). While it is in 0.x, a minor version (0.6 → 0.7)
may change behaviour or configuration: every such change is in `CHANGELOG.md` and, when you
have something to do, in `UPGRADE.md`. A patch version (0.6.0 → 0.6.1) never does.
