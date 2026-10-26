<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/** Small helpers shared by the detectors. Names are resolved (NameResolver ran). */
final class Ast
{
    public const REQUEST = 'Symfony\Component\HttpFoundation\Request';

    /** @return list<Class_> every named class of the file */
    public static function classes(SourceFile $file): array
    {
        /** @var list<Class_> $classes */
        $classes = (new NodeFinder())->find($file->ast() ?? [], static fn (Node $n) => $n instanceof Class_ && null !== $n->name);

        return $classes;
    }

    public static function is(?Node\Name $name, string ...$classes): bool
    {
        return null !== $name && \in_array(ltrim($name->toString(), '\\'), $classes, true);
    }

    /** @param array<Node\AttributeGroup> $groups */
    public static function hasAttribute(array $groups, string ...$classes): bool
    {
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                if (self::is($attr->name, ...$classes)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A controller: extends AbstractController, #[AsController], a #[Route], or a *Controller class. */
    public static function isController(Class_ $class): bool
    {
        $route = ['Symfony\Component\Routing\Attribute\Route', 'Symfony\Component\Routing\Annotation\Route'];
        if (self::is($class->extends, 'Symfony\Bundle\FrameworkBundle\Controller\AbstractController')
            || self::hasAttribute($class->attrGroups, 'Symfony\Component\HttpKernel\Attribute\AsController', ...$route)
            || str_ends_with((string) $class->name, 'Controller')) {
            return true;
        }
        foreach ($class->getMethods() as $m) {
            if (self::hasAttribute($m->attrGroups, ...$route)) {
                return true;
            }
        }

        return false;
    }

    /** An event listener or subscriber. */
    public static function isListener(Class_ $class): bool
    {
        foreach ($class->implements as $i) {
            if (self::is($i, 'Symfony\Component\EventDispatcher\EventSubscriberInterface')) {
                return true;
            }
        }
        if (self::hasAttribute($class->attrGroups, 'Symfony\Component\EventDispatcher\Attribute\AsEventListener')) {
            return true;
        }
        foreach ($class->getMethods() as $m) {
            if (self::hasAttribute($m->attrGroups, 'Symfony\Component\EventDispatcher\Attribute\AsEventListener')) {
                return true;
            }
        }

        return str_ends_with((string) $class->name, 'Listener') || str_ends_with((string) $class->name, 'Subscriber');
    }

    /** @return list<string> the names of the parameters typed Request */
    public static function requestParams(ClassMethod $method): array
    {
        $names = [];
        foreach ($method->params as $p) {
            if ($p->type instanceof Node\Name && self::is($p->type, self::REQUEST) && $p->var instanceof Node\Expr\Variable && \is_string($p->var->name)) {
                $names[] = $p->var->name;
            }
        }

        return $names;
    }

    /** @param list<string> $names */
    public static function isVar(Node $node, array $names): bool
    {
        return $node instanceof Node\Expr\Variable && \is_string($node->name) && \in_array($node->name, $names, true);
    }

    public static function methodName(Node $node): ?string
    {
        return ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) && $node->name instanceof Node\Identifier ? $node->name->toLowerString() : null;
    }
}
