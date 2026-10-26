<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt\Catalog;

use Acencyril\UpliftBundle\Adopt\Adoption;
use Acencyril\UpliftBundle\Adopt\Ast;
use Acencyril\UpliftBundle\Adopt\SourceFile;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * PHP 8.1: readonly properties. A service's dependencies, promoted in its constructor,
 * are set once and never written again: readonly says so, and PHP enforces it.
 *
 * Candidates, conservative on purpose: a promoted, typed constructor parameter without
 * readonly, in a class that is not readonly and not a Doctrine entity or document,
 * private (or protected/public in a final class, so no child class can write it), and
 * never written anywhere in the class: no assignment, compound assignment, ++/--,
 * unset, reference, by-reference foreach or by-reference built-in on any ->name of
 * that name.
 */
final class ReadonlyPromotion implements Adoption
{
    /** built-ins that write their argument (by reference) */
    private const BY_REF = ['preg_match', 'preg_match_all', 'sort', 'rsort', 'usort', 'uasort', 'uksort', 'ksort', 'krsort', 'asort', 'arsort', 'natsort', 'natcasesort', 'shuffle',
        'array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice', 'array_walk', 'array_walk_recursive', 'array_multisort', 'settype', 'parse_str', 'end', 'reset', 'next', 'prev'];

    public function id(): string
    {
        return 'readonly-promotion';
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
        return 'https://www.php.net/releases/8.1/en.php';
    }

    public function rector(): ?string
    {
        return 'Rector\Php81\Rector\Property\ReadOnlyPropertyRector';
    }

    public function experimental(): bool
    {
        return false;
    }

    public function detect(SourceFile $file): array
    {
        $out = [];
        foreach (Ast::classes($file) as $class) {
            if ($class->isReadonly() || $this->isMapped($class)) {
                continue;
            }
            $constructor = $class->getMethod('__construct');
            if (null === $constructor) {
                continue;
            }
            $written = null;
            foreach ($constructor->params as $param) {
                $visibility = $param->flags & Modifiers::VISIBILITY_MASK;
                if (0 === $visibility || 0 !== ($param->flags & Modifiers::READONLY)
                    || null === $param->type || !$param->var instanceof Expr\Variable || !\is_string($param->var->name)
                    || (property_exists($param, 'hooks') && [] !== $param->hooks)
                    || (Modifiers::PRIVATE !== $visibility && !$class->isFinal())) {
                    continue;
                }
                $written ??= $this->written($class);
                if (isset($written[$param->var->name])) {
                    continue;
                }
                $keyword = [Modifiers::PRIVATE => 'private', Modifiers::PROTECTED => 'protected'][$visibility] ?? 'public';
                $out[] = $file->candidate($param, sprintf('%s readonly … $%s', $keyword, $param->var->name));
            }
        }

        return $out;
    }

    private function isMapped(Node\Stmt\Class_ $class): bool
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (preg_match('/\\\\(Entity|Embeddable|MappedSuperclass|Document|EmbeddedDocument)$/', '\\'.$attr->name->toString())) {
                    return true;
                }
            }
        }

        return null !== ($doc = $class->getDocComment()) && preg_match('/@(ORM\\\\|ODM\\\\)?(Entity|Embeddable|MappedSuperclass|Document)\b/', $doc->getText());
    }

    /** @return array<string, true> the property names written somewhere in the class, on any object */
    private function written(Node\Stmt\Class_ $class): array
    {
        $names = [];
        foreach ((new NodeFinder())->find($class->stmts, static fn (Node $n) => true) as $n) {
            $targets = match (true) {
                $n instanceof Expr\Assign, $n instanceof Expr\AssignOp, $n instanceof Expr\PreInc, $n instanceof Expr\PostInc,
                $n instanceof Expr\PreDec, $n instanceof Expr\PostDec => [$n->var],
                $n instanceof Expr\AssignRef => [$n->var, $n->expr],
                $n instanceof Node\Stmt\Unset_ => $n->vars,
                $n instanceof Node\Stmt\Foreach_ && $n->byRef => [$n->expr],
                $n instanceof Expr\FuncCall && $n->name instanceof Node\Name && \in_array($n->name->toLowerString(), self::BY_REF, true)
                    => array_map(static fn ($a) => $a instanceof Node\Arg ? $a->value : null, $n->args),
                $n instanceof Node\Arg && $n->byRef => [$n->value],
                default => [],
            };
            foreach ($targets as $t) {
                foreach ($this->propertyNames($t) as $name) {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function propertyNames(?Node $target): array
    {
        while ($target instanceof Expr\ArrayDimFetch) {
            $target = $target->var;
        }
        if (($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch) && $target->name instanceof Node\Identifier) {
            return [$target->name->toString()];
        }
        if ($target instanceof Expr\List_ || $target instanceof Expr\Array_) {
            $names = [];
            foreach ($target->items as $item) {
                array_push($names, ...$this->propertyNames($item?->value));
            }

            return $names;
        }

        return [];
    }
}
