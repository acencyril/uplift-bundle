<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

/** A place in the site's code that could use a new feature. */
final class Candidate
{
    /**
     * @param string $file    path relative to the project
     * @param string $snippet the line, trimmed
     * @param string $hint    what to use there, e.g. "#[MapQueryParameter] int $page"
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly string $snippet,
        public readonly string $hint = '',
    ) {
    }

    public function where(): string
    {
        return $this->file.':'.$this->line;
    }

    /** @return array{file: string, line: int, snippet: string, hint: string} */
    public function toArray(): array
    {
        return ['file' => $this->file, 'line' => $this->line, 'snippet' => $this->snippet, 'hint' => $this->hint];
    }
}
