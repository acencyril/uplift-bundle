<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Tests;

use Acencyril\UpliftBundle\Adopt\Adoption;
use Acencyril\UpliftBundle\Adopt\Candidate;
use Acencyril\UpliftBundle\Adopt\Catalog;
use Acencyril\UpliftBundle\Adopt\Catalog\AsCommandAttribute;
use Acencyril\UpliftBundle\Adopt\Catalog\LockAttribute;
use Acencyril\UpliftBundle\Adopt\Catalog\MapRequest;
use Acencyril\UpliftBundle\Adopt\Catalog\ReadonlyPromotion;
use Acencyril\UpliftBundle\Adopt\Scanner;
use Acencyril\UpliftBundle\Adopt\SourceFile;
use Acencyril\UpliftBundle\Semver\Version;
use PHPUnit\Framework\TestCase;

/** The detectors on tests/Fixtures/adopt/src: every hit, and the look-alikes that are not. */
final class AdoptDetectionTest extends TestCase
{
    private const DIR = __DIR__.'/Fixtures/adopt';

    /** @return list<string> "file:line" */
    private function where(Adoption $adoption, string $path = 'src'): array
    {
        $scan = (new Scanner())->scan(self::DIR, [$path], [$adoption]);

        return array_map(static fn (Candidate $c) => $c->where(), $scan['candidates'][$adoption->id()]);
    }

    public function testMapRequestFindsManualDecodingInControllerActionsOnly(): void
    {
        self::assertSame([
            'src/Controller/ApiController.php:20',
            'src/Controller/ApiController.php:28',
            'src/Controller/ApiController.php:29',
            'src/Controller/ApiController.php:38',
        ], $this->where(new MapRequest()), 'not: a dynamic query key, json_decode of another string, a private helper, a service, a controller already using the attributes');

        $scan = (new Scanner())->scan(self::DIR, ['src/Controller/ApiController.php'], [new MapRequest()]);
        $hints = array_map(static fn (Candidate $c) => $c->hint, $scan['candidates']['map-request']);
        self::assertSame('#[MapQueryParameter] $page', $hints[1]);
        self::assertStringContainsString('#[MapQueryString]', $hints[2]);
        self::assertSame('$data = json_decode($request->getContent(), true);', $scan['candidates']['map-request'][0]->snippet);
    }

    public function testAsCommandFindsTheStaticPropertiesOfCommandsWithoutTheAttribute(): void
    {
        self::assertSame(['src/Command/LegacyCommand.php:11', 'src/Command/LegacyCommand.php:12'], $this->where(new AsCommandAttribute()),
            'not: a command that has #[AsCommand] already, a class that is not a command');
    }

    public function testReadonlyPromotionIsConservative(): void
    {
        self::assertSame([
            'src/Controller/ApiController.php:13',
            'src/EventListener/InvoiceLockSubscriber.php:11',
            'src/Service/Feed.php:11',
            'src/Service/Feed.php:12',
        ], $this->where(new ReadonlyPromotion()), 'not: written ([]=, ++, +=, by-ref built-in, by-ref foreach, on a clone), protected in a non-final class, untyped, readonly already, an entity');
    }

    public function testLockFindsCreateLockInControllersAndListeners(): void
    {
        self::assertSame([
            'src/Controller/ApiController.php:44',
            'src/EventListener/InvoiceLockSubscriber.php:22',
        ], $this->where(new LockAttribute()), 'not: a plain service');
        self::assertTrue((new LockAttribute())->experimental());
        self::assertNull((new LockAttribute())->rector());
    }

    public function testAFileThatDoesNotParseIsReportedAndSkipped(): void
    {
        $scan = (new Scanner())->scan(self::DIR, ['src'], [new MapRequest()]);
        self::assertSame(10, $scan['files']);
        self::assertCount(1, $scan['errors']);
        self::assertStringStartsWith('src/Service/Broken.php does not parse', $scan['errors'][0]);
    }

    public function testNothingToScanReadsNothing(): void
    {
        self::assertSame(['candidates' => [], 'files' => 0, 'errors' => []], (new Scanner())->scan(self::DIR, ['src'], []));
        self::assertSame([], $this->where(new MapRequest(), 'missing'));
    }

    public function testTheCatalogEntriesAreWellFormed(): void
    {
        $catalog = new Catalog([new MapRequest(), new AsCommandAttribute(), new ReadonlyPromotion(), new LockAttribute()]);
        self::assertSame(['as-command', 'lock-attribute', 'map-request', 'readonly-promotion'], array_map(static fn (Adoption $a) => $a->id(), $catalog->all()));
        foreach ($catalog->all() as $a) {
            self::assertNotNull(Version::parse($a->minVersion()), $a->id());
            self::assertStringStartsWith('https://', $a->docs());
            self::assertTrue('php' === $a->package() || str_contains($a->package(), '/'));
        }
        $translations = ['en' => require __DIR__.'/../translations/uplift.en.php', 'fr' => require __DIR__.'/../translations/uplift.fr.php'];
        foreach ($translations as $locale => $t) {
            foreach ($catalog->all() as $a) {
                self::assertArrayHasKey('adoption.'.$a->id().'.title', $t, $locale);
                self::assertArrayHasKey('adoption.'.$a->id().'.summary', $t, $locale);
            }
        }
        self::assertSame(array_keys($translations['en']), array_keys($translations['fr']));
    }

    public function testTheCatalogRefusesBadOrDuplicateIds(): void
    {
        $bad = new class implements Adoption {
            public string $id = 'Not Valid';

            public function id(): string
            {
                return $this->id;
            }

            public function package(): string
            {
                return 'php';
            }

            public function minVersion(): string
            {
                return '8.1';
            }

            public function docs(): string
            {
                return 'https://www.php.net';
            }

            public function rector(): ?string
            {
                return null;
            }

            public function experimental(): bool
            {
                return false;
            }

            public function detect(SourceFile $file): array
            {
                return [];
            }
        };
        try {
            new Catalog([$bad]);
            self::fail('a bad id');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new Catalog([new MapRequest(), new MapRequest()]);
    }
}
