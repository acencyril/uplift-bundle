<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Walks the site's PHP files (src/ by default) and runs the detectors on each. Static:
 * the code is parsed, never loaded or run.
 */
final class Scanner
{
    private ?Parser $parser = null;

    /**
     * @param list<Adoption> $adoptions
     * @param list<string>   $paths     directories or files, relative to $projectDir or absolute
     *
     * @return array{candidates: array<string, list<Candidate>>, files: int, errors: list<string>}
     */
    public function scan(string $projectDir, array $paths, array $adoptions): array
    {
        $candidates = array_fill_keys(array_map(static fn (Adoption $a) => $a->id(), $adoptions), []);
        $errors = [];
        $count = 0;
        if ([] === $adoptions) {
            return ['candidates' => $candidates, 'files' => 0, 'errors' => []];
        }
        $this->parser ??= (new ParserFactory())->createForNewestSupportedVersion();
        $root = rtrim($projectDir, '/');
        foreach ($this->files($root, $paths) as $path) {
            ++$count;
            $relative = str_starts_with($path, $root.'/') ? substr($path, \strlen($root) + 1) : $path;
            $file = new SourceFile($path, $relative, (string) file_get_contents($path), $this->parser);
            if (null === $file->ast()) {
                $errors[] = sprintf('%s does not parse: %s', $relative, $file->error());
                continue;
            }
            foreach ($adoptions as $adoption) {
                foreach ($adoption->detect($file) as $candidate) {
                    $candidates[$adoption->id()][] = $candidate;
                }
            }
        }

        return ['candidates' => $candidates, 'files' => $count, 'errors' => $errors];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string> absolute paths, sorted, vendor/ and var/ left out
     */
    private function files(string $root, array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $p = str_starts_with($p, '/') ? $p : $root.'/'.$p;
            if (is_file($p) && str_ends_with($p, '.php')) {
                $out[] = $p;
                continue;
            }
            if (!is_dir($p)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($p, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f) => $f->isDir() ? !\in_array($f->getFilename(), ['vendor', 'var', 'node_modules', '.git'], true) : str_ends_with($f->getFilename(), '.php'),
            ));
            foreach ($it as $f) {
                $out[] = $f->getPathname();
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }
}
