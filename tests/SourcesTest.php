<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Source\PackagistSource;
use Acencyril\UpliftBundle\Source\PhpNetSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** The real sources, against recorded-shape answers (no network). */
final class SourcesTest extends TestCase
{
    public function testPackagistMinifiedMetadataIsExpanded(): void
    {
        $body = (string) json_encode([
            'minified' => 'composer/2.0',
            'packages' => ['acme/lib' => [
                ['name' => 'acme/lib', 'version' => 'v2.1.0', 'require' => ['php' => '>=8.2']],
                ['version' => 'v2.0.0'],
                ['version' => 'v2.0.0-RC1'],
                ['version' => 'v1.9.0', 'require' => '__unset'],
            ]],
        ]);
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url) use ($body, &$requests) {
            $requests[] = $url;

            return new MockResponse($body);
        });
        $releases = (new PackagistSource($http, 'https://packagist.test'))->releases('acme/lib');

        self::assertSame(['https://packagist.test/p2/acme/lib.json'], $requests);
        self::assertSame(['2.1.0', '2.0.0', '1.9.0'], array_map(static fn ($r) => (string) $r->version, $releases));
        self::assertSame(['>=8.2', '>=8.2', null], array_map(static fn ($r) => $r->php, $releases), 'inherited, then unset');
    }

    public function testPackagistIsNotAskedForANameThatIsNotAPackage(): void
    {
        $http = new MockHttpClient(static fn () => self::fail('no request'));
        self::assertSame([], (new PackagistSource($http))->releases('../../etc'));
    }

    public function testUnknownPackageIsEmpty(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 404]));
        self::assertSame([], (new PackagistSource($http))->releases('acme/nothing'));
    }

    public function testPhpNetActiveBranchesAndAnOldOne(): void
    {
        $active = ['8' => [
            '8.2' => ['announcement' => true, 'tags' => ['security'], 'date' => '03 Jul 2025', 'version' => '8.2.29'],
            '8.3' => ['announcement' => true, 'tags' => [], 'date' => '25 Sep 2025', 'version' => '8.3.26'],
            '8.4' => ['announcement' => true, 'tags' => [], 'date' => '25 Sep 2025', 'version' => '8.4.13'],
        ]];
        $old = ['announcement' => true, 'tags' => ['security'], 'date' => '03 Jul 2025', 'source' => [['filename' => 'php-8.1.33.tar.gz']], 'version' => '8.1.33'];
        $http = new MockHttpClient(static fn (string $m, string $url) => new MockResponse((string) json_encode(str_contains($url, 'active.php') ? $active : $old)));

        $branches = (new PhpNetSource($http, 'https://php.test'))->branches(['8.1', '8.3']);
        self::assertSame(['8.1.33', '8.2.29', '8.3.26', '8.4.13'], array_map(static fn ($b) => (string) $b->latest, $branches));
        self::assertSame([false, true, true, true], array_map(static fn ($b) => $b->supported, $branches));
    }
}
