<?php

declare(strict_types=1);

namespace Acencyril\UpliftBundle\Adopt\Catalog;

use Acencyril\UpliftBundle\Adopt\Adoption;
use Acencyril\UpliftBundle\Adopt\Ast;
use Acencyril\UpliftBundle\Adopt\SourceFile;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeFinder;

/**
 * EXPERIMENTAL: an example of an adoption for a feature that is not confirmed.
 *
 * The idea: a controller attribute that takes a lock around the action, e.g.
 * #[Lock('invoice-{id}', blocking: true, ttl: 10)], instead of a LockFactory injected in
 * a controller (or a listener) that calls createLock() then acquire()/release() by hand.
 *
 * TODO: no such attribute exists in Symfony 7.4 (symfony/lock and symfony/http-kernel
 * have none). When the release that ships it is out, confirm the package, the first
 * version, the class name, its arguments and the documentation link, then set
 * experimental() to false. Until then this adoption is listed and never applied.
 *
 * Candidates: a controller or an event listener/subscriber with a method that calls
 * ->createLock() and ->acquire() or ->release().
 */
final class LockAttribute implements Adoption
{
    public function id(): string
    {
        return 'lock-attribute';
    }

    public function package(): string
    {
        // TODO: confirm which package ships it (symfony/framework-bundle or symfony/lock)
        return 'symfony/framework-bundle';
    }

    public function minVersion(): string
    {
        // TODO: confirm the version
        return '8.2';
    }

    public function docs(): string
    {
        // the Lock component today; TODO: the page of the attribute once it exists
        return 'https://symfony.com/doc/current/lock.html';
    }

    public function rector(): ?string
    {
        return null;
    }

    public function experimental(): bool
    {
        return true;
    }

    public function detect(SourceFile $file): array
    {
        $out = [];
        $finder = new NodeFinder();
        foreach (Ast::classes($file) as $class) {
            $controller = Ast::isController($class);
            if (!$controller && !Ast::isListener($class)) {
                continue;
            }
            foreach ($class->getMethods() as $method) {
                $calls = $finder->find($method->stmts ?? [], static fn (Node $n) => $n instanceof MethodCall);
                $names = array_map(static fn (Node $c) => Ast::methodName($c), $calls);
                if (!\in_array('acquire', $names, true) && !\in_array('release', $names, true)) {
                    continue;
                }
                foreach ($calls as $call) {
                    if ('createlock' === Ast::methodName($call)) {
                        $out[] = $file->candidate($call, $controller ? 'a lock attribute on the action (API to confirm)' : 'a lock attribute on the actions this listener guards (API to confirm)');
                    }
                }
            }
        }

        return $out;
    }
}
