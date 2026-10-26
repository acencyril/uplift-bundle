<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Composer;

use Acencyril\UpliftBundle\Semver\Constraint;
use Acencyril\UpliftBundle\Semver\Version;

/**
 * A project's composer.json and composer.lock, read once. The JSON text is kept as
 * written so that rewriting a constraint changes that constraint only.
 */
final class ComposerProject
{
    /** @var array<string, mixed> */
    private array $json;
    private string $text;
    /** @var array<string, string> installed version by package name */
    private array $locked = [];

    public function __construct(public readonly string $dir)
    {
        $file = $dir.'/composer.json';
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('No composer.json in %s.', $dir));
        }
        $this->text = (string) file_get_contents($file);
        $json = json_decode($this->text, true);
        if (!\is_array($json)) {
            throw new \RuntimeException(sprintf('%s is not valid JSON.', $file));
        }
        $this->json = $json;

        $lock = is_file($dir.'/composer.lock') ? json_decode((string) file_get_contents($dir.'/composer.lock'), true) : null;
        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($lock[$section] ?? null) ? $lock[$section] : [] as $p) {
                if (isset($p['name'], $p['version'])) {
                    $this->locked[strtolower((string) $p['name'])] = (string) $p['version'];
                }
            }
        }
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Direct requirements that are packages (not php, ext-*, lib-*, composer-*).
     *
     * @return array<string, array{constraint: string, dev: bool}>
     */
    public function requirements(): array
    {
        $out = [];
        foreach (['require' => false, 'require-dev' => true] as $section => $dev) {
            foreach (\is_array($this->json[$section] ?? null) ? $this->json[$section] : [] as $name => $constraint) {
                $name = (string) $name;
                if (!str_contains($name, '/') || !\is_string($constraint)) {
                    continue;
                }
                $out[$name] = ['constraint' => $constraint, 'dev' => $dev];
            }
        }

        return $out;
    }

    public function section(string $package): ?string
    {
        foreach (['require', 'require-dev'] as $section) {
            if (isset($this->json[$section][$package])) {
                return $section;
            }
        }

        return null;
    }

    public function installed(string $package): ?Version
    {
        return Version::parse($this->locked[strtolower($package)] ?? '');
    }

    /** @return array<string, string> the version of every locked package, by lowercase name */
    public function lockedVersions(): array
    {
        return $this->locked;
    }

    public function hasLock(): bool
    {
        return [] !== $this->locked;
    }

    public function requirePhp(): ?string
    {
        $php = $this->json['require']['php'] ?? null;

        return \is_string($php) ? $php : null;
    }

    public function platformPhp(): ?string
    {
        $php = $this->json['config']['platform']['php'] ?? null;

        return \is_string($php) ? $php : null;
    }

    /** extra.symfony.require: the Symfony version Flex holds every symfony/* package to. */
    public function symfonyRequire(): ?string
    {
        $r = $this->json['extra']['symfony']['require'] ?? null;

        return \is_string($r) ? $r : null;
    }

    /**
     * The PHP version the project is built for: config.platform.php when set, else the
     * runtime when it is on the branch require.php starts from, else that lower bound.
     */
    public function phpVersion(string $runtime = \PHP_VERSION): ?Version
    {
        if (null !== ($platform = $this->platformPhp()) && null !== ($v = Version::parse($platform))) {
            return $v;
        }
        $floor = null !== $this->requirePhp() ? Constraint::parse($this->requirePhp())?->lowerBound() : null;
        $run = Version::parse(preg_replace('/[-+].*$/', '', $runtime) ?? '');
        if (null === $floor || '0.0.0' === (string) $floor) {
            return $run;
        }
        if (null !== $run && $run->branch() === $floor->branch() && !$floor->greaterThan($run)) {
            return $run;
        }

        return $floor;
    }
}
