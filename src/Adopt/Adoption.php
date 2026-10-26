<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

/**
 * One new feature a site can use from a given version of a package: once the site runs
 * that version, code that does the same thing by hand is a candidate for the feature.
 *
 * Implement it in your application (autoconfigured services are tagged
 * "uplift.adoption") or in a bundle (tag the service yourself). The title and the short
 * explanation are translation keys in the "uplift" domain:
 * adoption.<id>.title and adoption.<id>.summary.
 */
interface Adoption
{
    public const TAG = 'uplift.adoption';

    /** a-z, 0-9 and "-": used in UPLIFT_ADOPT_SKIP, the translation keys and the Rehearsal change id */
    public function id(): string;

    /** "php" or a Composer package name, e.g. "symfony/http-kernel" */
    public function package(): string;

    /** The first version of package() with the feature, e.g. "6.3" */
    public function minVersion(): string;

    /** The upstream documentation or announcement */
    public function docs(): string;

    /**
     * A Rector rule class that rewrites the candidates, run by "uplift:adopt --apply"
     * (Rector 1.0 or later in the site); null when the change is made by hand.
     */
    public function rector(): ?string;

    /** A feature not confirmed upstream yet: listed, flagged, never applied. */
    public function experimental(): bool;

    /** @return list<Candidate> the places in this file that could use the feature */
    public function detect(SourceFile $file): array;
}
