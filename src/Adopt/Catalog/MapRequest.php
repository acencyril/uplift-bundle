<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt\Catalog;

use Acencyril\UpliftBundle\Adopt\Adoption;
use Acencyril\UpliftBundle\Adopt\Ast;
use Acencyril\UpliftBundle\Adopt\SourceFile;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\NodeFinder;

/**
 * Symfony 6.3 (symfony/http-kernel): #[MapRequestPayload], #[MapQueryString] and
 * #[MapQueryParameter] map the request to typed, validated controller arguments.
 *
 * Candidates, in controller actions only, on a parameter typed Request:
 *   json_decode($request->getContent(), …) and $request->toArray()  -> #[MapRequestPayload]
 *   $request->query->get('page'), ->getInt('page')…                  -> #[MapQueryParameter]
 *   $request->query->all()                                            -> #[MapQueryString]
 *
 * No automatic rewrite: the target DTO has to be written by a person.
 */
final class MapRequest implements Adoption
{
    private const QUERY_GETTERS = ['get', 'getint', 'getstring', 'getboolean', 'getalpha', 'getalnum', 'getdigits', 'getenum', 'filter'];

    public function id(): string
    {
        return 'map-request';
    }

    public function package(): string
    {
        return 'symfony/http-kernel';
    }

    public function minVersion(): string
    {
        return '6.3';
    }

    public function docs(): string
    {
        return 'https://symfony.com/doc/current/controller.html';
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
        $out = [];
        $finder = new NodeFinder();
        foreach (Ast::classes($file) as $class) {
            if (!Ast::isController($class)) {
                continue;
            }
            foreach ($class->getMethods() as $method) {
                $request = Ast::requestParams($method);
                if (!$method->isPublic() || $method->isStatic() || [] === $request) {
                    continue;
                }
                foreach ($finder->find($method->stmts ?? [], static fn (Node $n) => $n instanceof FuncCall || $n instanceof MethodCall) as $call) {
                    if ($call instanceof FuncCall) {
                        $arg = $call->args[0] ?? null;
                        if ($call->name instanceof Node\Name && 'json_decode' === $call->name->toLowerString()
                            && $arg instanceof Node\Arg && 'getcontent' === Ast::methodName($arg->value) && Ast::isVar($arg->value->var, $request)) {
                            $out[] = $file->candidate($call, '#[MapRequestPayload] on a typed argument instead of json_decode');
                        }
                        continue;
                    }
                    $name = Ast::methodName($call);
                    if ('toarray' === $name && [] === $call->args && Ast::isVar($call->var, $request)) {
                        $out[] = $file->candidate($call, '#[MapRequestPayload] on a typed argument instead of $request->toArray()');
                    } elseif ($call->var instanceof PropertyFetch && $call->var->name instanceof Node\Identifier && 'query' === $call->var->name->toString() && Ast::isVar($call->var->var, $request)) {
                        $first = $call->args[0] ?? null;
                        if (\in_array($name, self::QUERY_GETTERS, true) && $first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
                            $out[] = $file->candidate($call, sprintf('#[MapQueryParameter] $%s', $first->value->value));
                        } elseif ('all' === $name && [] === $call->args) {
                            $out[] = $file->candidate($call, '#[MapQueryString] on a typed argument');
                        }
                    }
                }
            }
        }

        return $out;
    }
}
