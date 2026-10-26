<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle;

/**
 * What Uplift may do on its own, read from the environment:
 *
 *   UPLIFT_ENABLED       0 or 1; at 0, uplift:apply changes nothing (default 0)
 *   UPLIFT_PHP           off|patch|minor|major, for the PHP the project targets (default patch)
 *   UPLIFT_SYMFONY       off|patch|minor|major, for the symfony/* packages that follow the framework (default patch)
 *   UPLIFT_PACKAGES      off|patch|minor|major, for every other package (default patch)
 *   UPLIFT_PHP_MAX       a PHP branch never to go above, e.g. 8.1 (default: none)
 *   UPLIFT_OWN_VENDOR    vendors you publish yourself, comma-separated (default acencyril; "none" for none)
 *   UPLIFT_TEST_COMMAND  the command that says the upgrade works (default vendor/bin/phpunit)
 *   UPLIFT_ADOPT         off|propose|apply, for the new features of the installed versions (default propose)
 *   UPLIFT_ADOPT_SKIP    adoption ids never to propose, comma-separated (default: none)
 *
 * A move above its level is proposed in the report, never applied.
 *
 * Own vendors: their packages move automatically up to a minor, whatever
 * UPLIFT_PACKAGES says (or further if UPLIFT_PACKAGES allows more). Below 1.0 a minor
 * is the breaking level (0.3 -> 0.4), so own 0.x packages follow their releases fully:
 * you write both sides and release them together. From 1.0 on, a major is a
 * deliberate break of a stable API, so it waits for someone to read its upgrade notes.
 */
final class Policy
{
    public const OWN_VENDOR_FLOOR = Level::Minor;
    public const ADOPT = ['off', 'propose', 'apply'];
    public const VARIABLES = ['UPLIFT_ENABLED', 'UPLIFT_PHP', 'UPLIFT_SYMFONY', 'UPLIFT_PACKAGES', 'UPLIFT_PHP_MAX', 'UPLIFT_OWN_VENDOR', 'UPLIFT_TEST_COMMAND', 'UPLIFT_ADOPT', 'UPLIFT_ADOPT_SKIP'];

    /**
     * @param list<string> $ownVendors
     * @param list<string> $adoptSkip
     */
    public function __construct(
        public readonly bool $enabled = false,
        public readonly Level $php = Level::Patch,
        public readonly Level $symfony = Level::Patch,
        public readonly Level $packages = Level::Patch,
        public readonly ?string $phpMax = null,
        public readonly array $ownVendors = ['acencyril'],
        public readonly string $testCommand = 'vendor/bin/phpunit',
        public readonly string $adopt = 'propose',
        public readonly array $adoptSkip = [],
    ) {
        if (!\in_array($adopt, self::ADOPT, true)) {
            throw new \InvalidArgumentException(sprintf('UPLIFT_ADOPT must be off, propose or apply, "%s" given.', $adopt));
        }
        if (null !== $phpMax && !preg_match('/^\d+\.\d+$/', $phpMax)) {
            throw new \InvalidArgumentException(sprintf('UPLIFT_PHP_MAX must be a PHP branch such as 8.1, "%s" given.', $phpMax));
        }
    }

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env): self
    {
        $get = static fn (string $k): ?string => isset($env[$k]) && \is_scalar($env[$k]) ? trim((string) $env[$k]) : null;
        $enabled = strtolower((string) $get('UPLIFT_ENABLED'));
        if (!\in_array($enabled, ['', '0', '1', 'true', 'false'], true)) {
            throw new \InvalidArgumentException(sprintf('UPLIFT_ENABLED must be 0 or 1, "%s" given.', $enabled));
        }
        $own = $get('UPLIFT_OWN_VENDOR');
        $own = null === $own ? ['acencyril'] : (\in_array(strtolower($own), ['', 'none', 'off'], true) ? [] : array_values(array_filter(array_map('trim', explode(',', strtolower($own))))));
        $max = $get('UPLIFT_PHP_MAX');
        $test = $get('UPLIFT_TEST_COMMAND');
        $adopt = strtolower((string) $get('UPLIFT_ADOPT'));
        $skip = array_values(array_filter(array_map('trim', explode(',', strtolower((string) $get('UPLIFT_ADOPT_SKIP'))))));

        return new self(
            \in_array($enabled, ['1', 'true'], true),
            Level::fromSetting('UPLIFT_PHP', $get('UPLIFT_PHP'), Level::Patch),
            Level::fromSetting('UPLIFT_SYMFONY', $get('UPLIFT_SYMFONY'), Level::Patch),
            Level::fromSetting('UPLIFT_PACKAGES', $get('UPLIFT_PACKAGES'), Level::Patch),
            '' === (string) $max ? null : $max,
            $own,
            null === $test ? 'vendor/bin/phpunit' : $test,
            '' === $adopt ? 'propose' : $adopt,
            $skip,
        );
    }

    /** The environment the process runs in: real variables first, then what Dotenv loaded. */
    public static function fromRuntime(): self
    {
        $env = [];
        foreach (self::VARIABLES as $k) {
            $v = $_SERVER[$k] ?? $_ENV[$k] ?? getenv($k);
            if (false !== $v && null !== $v) {
                $env[$k] = $v;
            }
        }

        return self::fromEnv($env);
    }

    public function isOwn(string $package): bool
    {
        return \in_array(strtolower(strtok($package, '/')), $this->ownVendors, true);
    }

    public function levelFor(string $category): Level
    {
        return match ($category) {
            'php' => $this->php,
            'symfony' => $this->symfony,
            'own' => Level::max($this->packages, self::OWN_VENDOR_FLOOR),
            default => $this->packages,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'php' => $this->php->value,
            'symfony' => $this->symfony->value,
            'packages' => $this->packages->value,
            'own_vendor' => $this->ownVendors,
            'own_vendor_level' => $this->levelFor('own')->value,
            'php_max' => $this->phpMax,
            'test_command' => $this->testCommand,
            'adopt' => $this->adopt,
            'adopt_skip' => $this->adoptSkip,
        ];
    }
}
