<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

/**
 * A PHP file of the site, parsed once (names resolved) and shared by every detector.
 * Detection only reads: nothing is ever written from here.
 */
final class SourceFile
{
    /** @var list<Node\Stmt>|null */
    private ?array $ast = null;
    private bool $parsed = false;
    private ?string $error = null;
    /** @var list<string>|null */
    private ?array $lines = null;

    public function __construct(
        public readonly string $path,
        public readonly string $relative,
        public readonly string $code,
        private readonly Parser $parser,
    ) {
    }

    /** @return list<Node\Stmt>|null null when the file does not parse */
    public function ast(): ?array
    {
        if (!$this->parsed) {
            $this->parsed = true;
            try {
                $stmts = $this->parser->parse($this->code) ?? [];
                $traverser = new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true]));
                /** @var list<Node\Stmt> $stmts */
                $stmts = $traverser->traverse($stmts);
                $this->ast = $stmts;
            } catch (Error $e) {
                $this->error = $e->getMessage();
            }
        }

        return $this->ast;
    }

    public function error(): ?string
    {
        $this->ast();

        return $this->error;
    }

    public function candidate(Node $node, string $hint = ''): Candidate
    {
        $line = max(1, $node->getStartLine());
        $this->lines ??= preg_split('/\R/', $this->code) ?: [];
        $snippet = trim($this->lines[$line - 1] ?? '');
        if (mb_strlen($snippet) > 160) {
            $snippet = mb_substr($snippet, 0, 159).'…';
        }

        return new Candidate($this->relative, $line, $snippet, $hint);
    }
}
