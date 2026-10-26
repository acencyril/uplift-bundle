<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt\Catalog;

use Acencyril\UpliftBundle\Adopt\Adoption;
use Acencyril\UpliftBundle\Adopt\Ast;
use Acencyril\UpliftBundle\Adopt\SourceFile;
use PhpParser\Node\Stmt\Property;

/**
 * Symfony 5.3 (symfony/console): #[AsCommand] gives a command its name and description,
 * so the command stays lazy. The static $defaultName / $defaultDescription properties
 * it replaces are deprecated since Symfony 6.1.
 *
 * Candidates: a class extending Console's Command, without #[AsCommand], that declares
 * protected static $defaultName or $defaultDescription.
 */
final class AsCommandAttribute implements Adoption
{
    public function id(): string
    {
        return 'as-command';
    }

    public function package(): string
    {
        return 'symfony/console';
    }

    public function minVersion(): string
    {
        return '5.3';
    }

    public function docs(): string
    {
        return 'https://symfony.com/doc/current/console.html';
    }

    public function rector(): ?string
    {
        // rector-symfony, Symfony 6.1 set (bundled with rector/rector)
        return 'Rector\Symfony\Symfony61\Rector\Class_\CommandPropertyToAttributeRector';
    }

    public function experimental(): bool
    {
        return false;
    }

    public function detect(SourceFile $file): array
    {
        $out = [];
        foreach (Ast::classes($file) as $class) {
            if (!Ast::is($class->extends, 'Symfony\Component\Console\Command\Command')
                || Ast::hasAttribute($class->attrGroups, 'Symfony\Component\Console\Attribute\AsCommand')) {
                continue;
            }
            foreach ($class->stmts as $stmt) {
                if (!$stmt instanceof Property || !$stmt->isStatic()) {
                    continue;
                }
                foreach ($stmt->props as $prop) {
                    if (\in_array($prop->name->toString(), ['defaultName', 'defaultDescription'], true)) {
                        $out[] = $file->candidate($stmt, '#[AsCommand] on the class');
                        break;
                    }
                }
            }
        }

        return $out;
    }
}
