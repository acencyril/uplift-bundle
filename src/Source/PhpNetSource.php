<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Source;

use Acencyril\UpliftBundle\Semver\Version;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * PHP releases from php.net: releases/active.php lists the supported branches with
 * their latest release; releases/index.php?json&version=X.Y gives the latest release
 * of any branch, supported or not.
 */
final class PhpNetSource implements PhpReleaseSource
{
    private readonly HttpClientInterface $http;

    public function __construct(?HttpClientInterface $http = null, private readonly string $baseUrl = 'https://www.php.net')
    {
        $this->http = $http ?? HttpClient::create();
    }

    public function branches(array $alsoBranches = []): array
    {
        $base = rtrim($this->baseUrl, '/');
        $out = [];
        foreach (self::versionsIn($this->http->request('GET', $base.'/releases/active.php', ['timeout' => 20])->toArray()) as $v) {
            $out[$v->branch()] = new PhpBranch($v, true);
        }
        foreach ($alsoBranches as $branch) {
            if (isset($out[$branch]) || !preg_match('/^\d+\.\d+$/', $branch)) {
                continue;
            }
            $latest = self::versionsIn($this->http->request('GET', $base.'/releases/index.php', ['query' => ['json' => '', 'version' => $branch], 'timeout' => 20])->toArray(false));
            foreach ($latest as $v) {
                if ($v->branch() === $branch) {
                    $out[$branch] = new PhpBranch($v, false);
                }
            }
        }
        uasort($out, static fn (PhpBranch $a, PhpBranch $b) => $a->latest->compare($b->latest));

        return array_values($out);
    }

    /**
     * Every "version" field in the answer, the highest per branch. Both files nest
     * releases differently; reading them this way keeps working if they move.
     *
     * @return list<Version>
     */
    public static function versionsIn(mixed $data): array
    {
        $found = [];
        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if (!\is_array($node)) {
                return;
            }
            if (isset($node['version']) && \is_string($node['version']) && null !== ($v = Version::parse($node['version'])) && 3 === $v->segments) {
                $b = $v->branch();
                if (!isset($found[$b]) || $v->greaterThan($found[$b])) {
                    $found[$b] = $v;
                }
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($data);

        return array_values($found);
    }
}
