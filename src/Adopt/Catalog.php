<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

/** Every adoption known to this site: the bundle's and the ones tagged "uplift.adoption". */
final class Catalog
{
    /** @var array<string, Adoption> */
    private array $adoptions = [];

    /** @param iterable<Adoption> $adoptions */
    public function __construct(iterable $adoptions)
    {
        foreach ($adoptions as $a) {
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/', $a->id())) {
                throw new \InvalidArgumentException(sprintf('Adoption id "%s" (%s): use a-z, 0-9 and "-", 48 characters at most.', $a->id(), $a::class));
            }
            if (isset($this->adoptions[$a->id()])) {
                throw new \InvalidArgumentException(sprintf('Two adoptions have the id "%s": %s and %s.', $a->id(), $this->adoptions[$a->id()]::class, $a::class));
            }
            $this->adoptions[$a->id()] = $a;
        }
        ksort($this->adoptions);
    }

    /** @return list<Adoption> */
    public function all(): array
    {
        return array_values($this->adoptions);
    }

    public function get(string $id): ?Adoption
    {
        return $this->adoptions[$id] ?? null;
    }
}
