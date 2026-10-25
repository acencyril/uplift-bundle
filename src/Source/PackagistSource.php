<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Source;

use Acencyril\UpliftBundle\Semver\Version;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The stable releases of a package from Packagist's metadata (repo.packagist.org/p2),
 * the same file Composer reads. Its "minified" format lists each version as the
 * differences from the one before; they are expanded here.
 */
final class PackagistSource implements ReleaseSource
{
    private readonly HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null, private readonly string $baseUrl = 'https://repo.packagist.org')
    {
        $this->http = $http ?? HttpClient::create();
    }

    public function releases(string $package): array
    {
        if (!preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#', $package)) {
            return [];
        }
        $response = $this->http->request('GET', rtrim($this->baseUrl, '/').'/p2/'.$package.'.json', ['timeout' => 20]);
        if (404 === $response->getStatusCode()) {
            return [];
        }

        return self::parse($response->toArray(), $package);
    }

    /**
     * @param array<string, mixed> $data the decoded p2 file
     *
     * @return list<Release>
     */
    public static function parse(array $data, string $package): array
    {
        $versions = $data['packages'][$package] ?? [];
        if (!\is_array($versions)) {
            return [];
        }
        $minified = 'composer/2.0' === ($data['minified'] ?? null);
        $out = [];
        $previous = null;
        foreach ($versions as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            if ($minified && null !== $previous) {
                $full = $previous;
                foreach ($entry as $k => $v) {
                    if ('__unset' === $v) {
                        unset($full[$k]);
                    } else {
                        $full[$k] = $v;
                    }
                }
                $entry = $full;
            }
            $previous = $entry;
            $version = Version::parse((string) ($entry['version'] ?? ''));
            if (null === $version) {
                continue; // a pre-release or a branch
            }
            $php = $entry['require']['php'] ?? null;
            $out[] = new Release($version, \is_string($php) ? $php : null);
        }

        return $out;
    }
}
