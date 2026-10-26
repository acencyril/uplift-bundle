<?php

// Assertions and facts of the e2e run, printed as GitHub annotations (::error:: and
// ::notice::) so that they can be read from the check run without the logs.
// Usage: php check.php <stage> <app dir> <logs dir>

[, $stage, $app, $logs] = $argv + [null, null, null, null];

function annotate(string $level, string $title, string $message): void
{
    $esc = static fn (string $s, bool $prop) => strtr($s, ['%' => '%25', "\r" => '%0D', "\n" => '%0A'] + ($prop ? [':' => '%3A', ',' => '%2C'] : []));
    echo '::'.$level.' title='.$esc($title, true).'::'.$esc(rtrim($message), false)."\n";
}
function fact(string $title, string $message): void
{
    annotate('notice', $title, $message);
}
$errors = 0;
function check(bool $ok, string $title, string $message): void
{
    global $errors;
    if (!$ok) {
        annotate('error', $title, $message);
        ++$errors;
    }
}
function json(string $file): array
{
    $text = is_file($file) ? (string) file_get_contents($file) : '';
    $start = strpos($text, '{');
    $data = false === $start ? null : json_decode(substr($text, $start), true);

    return is_array($data) ? $data : [];
}
function locked(string $lockFile): array
{
    $out = [];
    $lock = json($lockFile);
    foreach (['packages', 'packages-dev'] as $s) {
        foreach ($lock[$s] ?? [] as $p) {
            $out[$p['name']] = $p['version'];
        }
    }
    ksort($out);

    return $out;
}
function tail(string $s, int $n = 2500): string
{
    return strlen($s) > $n ? '…'.substr($s, -$n) : $s;
}
/** "Tests: 2, Assertions: 3, Deprecations: 1." or "OK (2 tests, 3 assertions)" and the deprecation count. */
function phpunitSummary(string $output): array
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn ($l) => preg_match('/^(OK|FAILURES|ERRORS|Tests:|No tests)/', $l)));
    $summary = implode(' ', array_slice($lines, -2));
    $deprecations = preg_match('/Deprecations: (\d+)/', $summary, $m) ? (int) $m[1] : 0;
    $phpunitDeprecations = preg_match('/PHPUnit Deprecations: (\d+)/', $summary, $m) ? (int) $m[1] : 0;

    return [$summary ?: '(no summary found)', $deprecations, $phpunitDeprecations];
}
function containerDeprecations(string $output): string
{
    if (preg_match('/There are no deprecations/i', $output)) {
        return '0';
    }
    $n = preg_match_all('/^\s*\[\d+\]|^\s*\d+x?\s/m', $output);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn ($l) => '' !== $l && !str_starts_with($l, '---')));

    return preg_match('/(\d+) deprecation/i', $output, $m) ? $m[1] : sprintf('%d line(s): %s', count($lines), tail(implode(' | ', $lines), 1200));
}

switch ($stage) {
    case 'before':
        [$summary, $dep, $pdep] = phpunitSummary((string) @file_get_contents("$logs/tests-before.log"));
        $lock = locked("$app/composer.lock");
        $symfony = array_filter($lock, static fn ($v, $k) => str_starts_with($k, 'symfony/'), ARRAY_FILTER_USE_BOTH);
        fact('Before', implode("\n", [
            'PHP runtime '.PHP_VERSION.', composer.json require.php '.(json("$app/composer.json")['require']['php'] ?? '-'),
            'symfony/framework-bundle '.($lock['symfony/framework-bundle'] ?? '?').' ('.count($symfony).' symfony/* packages locked)',
            'Tests (vendor/bin/phpunit --testsuite app): '.$summary,
            'PHPUnit deprecations counted in src: '.$dep,
            'Container deprecations (debug:container --deprecations): '.containerDeprecations((string) @file_get_contents("$logs/deprecations-before.log")),
        ]));
        break;

    case 'plan':
        $plan = json("$logs/plan.log");
        check([] !== $plan, 'uplift:plan', 'no JSON in the output: '.tail((string) @file_get_contents("$logs/plan.log")));
        $moves = $plan['moves'] ?? [];
        $sf = array_values(array_filter($moves, static fn ($m) => 'symfony/*' === $m['package'] && 'apply' === $m['decision']));
        check(1 === count($sf) && str_starts_with($sf[0]['from'], '7.3.') && str_starts_with($sf[0]['to'], '7.4.') && 'minor' === $sf[0]['level'],
            'uplift:plan does not propose Symfony 7.3 -> 7.4', json_encode($moves, JSON_UNESCAPED_SLASHES));
        $lines = array_map(static fn ($m) => sprintf('%s %s: %s %s -> %s (%s)%s', $m['decision'], $m['category'], $m['package'], $m['from'], $m['to'], $m['level'],
            ($m['constraint_to'] ? sprintf(' [%s -> %s]', $m['constraint_from'], $m['constraint_to']) : '').('' !== $m['reason'] ? ' - '.$m['reason'] : '')), $moves);
        fact('uplift:plan', 'PHP '.($plan['php']['current'] ?? '?').' -> '.($plan['php']['after'] ?? '?')."\n".implode("\n", $lines)."\nNotes: ".implode(' | ', $plan['notes'] ?? []));
        break;

    case 'apply':
        $json = json("$app/composer.json");
        $report = json("$app/var/uplift/report.json");
        $result = $report['result'] ?? [];
        if ([] === $report) {
            $raw = (string) @file_get_contents("$app/var/uplift/report.json");
            json_decode($raw);
            check(false, 'report.json unreadable', sprintf('%d bytes, %s: %s', strlen($raw), json_last_error_msg(), substr($raw, 0, 800)));
            check(false, 'uplift:apply output', tail((string) @file_get_contents("$logs/apply.log"), 3500));
        }
        check('7.4.*' === ($json['require']['symfony/framework-bundle'] ?? null), 'composer.json', 'symfony/framework-bundle is '.var_export($json['require']['symfony/framework-bundle'] ?? null, true).', expected 7.4.*');
        check('7.4.*' === ($json['extra']['symfony']['require'] ?? null), 'composer.json', 'extra.symfony.require is '.var_export($json['extra']['symfony']['require'] ?? null, true));
        $before = locked("$logs/before.composer.lock");
        $after = locked("$app/composer.lock");
        check(str_starts_with($after['symfony/framework-bundle'] ?? '', 'v7.4.'), 'composer.lock', 'symfony/framework-bundle locked at '.($after['symfony/framework-bundle'] ?? '?'));
        $stale = array_keys(array_filter($after, static fn ($v, $k) => str_starts_with($k, 'symfony/') && str_starts_with($v, 'v7.3.'), ARRAY_FILTER_USE_BOTH));
        check([] === $stale, 'composer.lock', 'symfony/* still on 7.3: '.implode(', ', $stale));
        check(is_file("$app/var/uplift/report.json") && is_file("$app/var/uplift/report.md"), 'report', 'var/uplift/report.json or report.md missing');
        check('passed' === ($result['steps']['tests']['status'] ?? null), 'uplift:apply tests step', 'status '.($result['status'] ?? '?').', tests '.json_encode($result['steps']['tests'] ?? null, JSON_UNESCAPED_SLASHES));
        check('passed' === ($report['status'] ?? null), 'uplift:apply status', ($report['status'] ?? '?').' - notes: '.implode(' | ', $result['notes'] ?? []).' - composer: '.tail($result['steps']['composer']['output'] ?? '', 1500));
        $changes = json("$app/.rehearsal/changes.json");
        $ids = array_column($changes['changes'] ?? [], 'id');
        check([] !== preg_grep('/^uplift-\d{4}-\d{2}-\d{2}$/', $ids), 'Rehearsal changes file', '.rehearsal/changes.json has no Uplift change: '.json_encode($ids));

        $moved = [];
        foreach ($after as $name => $v) {
            if (($before[$name] ?? null) !== $v) {
                $moved[] = sprintf('%s %s -> %s', $name, $before[$name] ?? '(new)', $v);
            }
        }
        foreach (array_diff_key($before, $after) as $name => $v) {
            $moved[] = sprintf('%s %s -> (removed)', $name, $v);
        }
        $sfMoved = array_filter($moved, static fn ($l) => str_starts_with($l, 'symfony/'));
        $other = array_filter($moved, static fn ($l) => !str_starts_with($l, 'symfony/'));
        fact('After uplift:apply - versions', sprintf("Status %s. PHP target %s -> %s (UPLIFT_PHP=off).\nApplied: %s\n%d symfony/* packages moved:\n%s",
            $report['status'] ?? '?', $report['php']['current'] ?? '?', $report['php']['after'] ?? '?',
            implode('; ', array_map(static fn ($m) => sprintf('%s %s -> %s', $m['package'], $m['from'], $m['to']), $result['applied'] ?? [])),
            count($sfMoved), implode("\n", $sfMoved)));
        fact('After uplift:apply - other packages moved', [] === $other ? 'none' : implode("\n", $other));
        fact('After uplift:apply - composer.json rewrites', implode("\n", $result['rewrites'] ?? []) ?: 'none');
        $steps = [];
        foreach ($result['steps'] ?? [] as $name => $s) {
            $steps[] = sprintf('%s: %s%s', $name, $s['status'], '' !== ($s['command'] ?? '') ? ' ('.$s['command'].')' : '');
        }
        fact('After uplift:apply - steps', implode("\n", $steps)."\nNotes: ".implode(' | ', array_merge($report['notes'] ?? [], $result['notes'] ?? [])));
        fact('After uplift:apply - Rector (project rector.php)', tail($result['steps']['rector']['output'] ?? '(no output)', 3000)."\n\ngit diff --stat:\n".(string) @file_get_contents("$logs/rector-diff.log"));
        [$summary, $dep] = phpunitSummary($result['steps']['tests']['output'] ?? '');
        preg_match_all('/^\d+\) \S+\n(.+)$/m', $result['steps']['tests']['output'] ?? '', $m);
        fact('After uplift:apply - tests', 'In uplift:apply: '.$summary."\nPHPUnit deprecations counted in src: ".$dep.([] !== $m[1] ? "\n- ".implode("\n- ", array_unique($m[1])) : ''));
        break;

    case 'after':
        [$summary, $dep] = phpunitSummary((string) @file_get_contents("$logs/tests-after.log"));
        fact('After', implode("\n", [
            'symfony/framework-bundle '.(locked("$app/composer.lock")['symfony/framework-bundle'] ?? '?'),
            'Tests (vendor/bin/phpunit --testsuite app, run again): '.$summary,
            'PHPUnit deprecations counted in src: '.$dep,
            'Container deprecations (debug:container --deprecations): '.containerDeprecations((string) @file_get_contents("$logs/deprecations-after.log")),
        ]));
        break;

    case 'adopt':
    case 'adopt-apply':
        $a = json("$app/var/uplift/report.json")['adoptions'] ?? [];
        check([] !== $a, $stage, 'no "adoptions" in var/uplift/report.json');
        $lines = [];
        foreach ($a['findings'] ?? [] as $f) {
            $lines[] = sprintf('%s (%s %s, installed %s): %s, rewrite %s, %d place(s)%s', $f['id'], $f['package'], $f['min_version'], $f['installed'] ?? '-', $f['decision'], $f['rewrite'],
                count($f['candidates']), implode('', array_map(static fn ($c) => "\n    ".$c['file'].':'.$c['line'].'  '.trim($c['snippet']), $f['candidates'])));
        }
        $outcomes = $a['result']['outcomes'] ?? [];
        foreach ($outcomes as $id => $o) {
            $lines[] = sprintf('result %s: %s', $id, $o['status'] ?? json_encode($o));
        }
        if ('adopt-apply' === $stage) {
            foreach ($a['findings'] ?? [] as $f) {
                if ('manual' !== $f['rewrite'] && !$f['experimental'] && [] !== $f['candidates']) {
                    $status = $outcomes[$f['id']]['status'] ?? 'none';
                    check('applied' === $status, 'uplift:adopt --apply '.$f['id'], sprintf('outcome %s with %s: %s', $status, $f['rewrite'], json_encode($outcomes[$f['id']] ?? null, JSON_UNESCAPED_SLASHES)));
                }
            }
            $lines[] = "git diff:\n".tail((string) @file_get_contents("$logs/adopt-diff.log"), 3000);
        }
        fact($stage, implode("\n", $lines)."\nNotes: ".implode(' | ', array_merge($a['notes'] ?? [], $a['result']['notes'] ?? [])));
        break;

    case 'rehearsal':
        $r = json("$app/var/e2e/rehearsal.json");
        check([] !== $r, 'Rehearsal', 'var/e2e/rehearsal.json was not written: the test did not reach the end');
        $status = json("$logs/rehearsal-status.log");
        check(($status['counts']['validated'] ?? 0) >= 1, 'rehearsal:status', tail((string) @file_get_contents("$logs/rehearsal-status.log")));
        fact('Rehearsal', "Listed in /en/admin/rehearsal (signed in as admin, HTTP basic; anonymous and wrong password got 401):\n"
            .implode("\n", array_map(static fn ($c) => sprintf('- %s: %s [%s] %s', $c['id'], $c['title'], $c['state'], $c['tests']), $r['listed'] ?? []))
            ."\nValidated: ".($r['validated'] ?? '?').' -> '.($r['state_after'] ?? '?').' ('.($r['flash'] ?? '').')'
            ."\nRecorded in var/rehearsal/decisions.json: ".json_encode($r['decisions_file'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ."\nrehearsal:status --json counts: ".json_encode($status['counts'] ?? null));
        break;

    case 'echo':
        $digest = (string) @file_get_contents("$logs/echo-digest.log");
        check(str_contains($digest, 'Sign-up: 1'), 'echo:digest', 'Sign-up: 1 not in the digest: '.tail($digest));
        check(str_contains($digest, 'Page views: 1'), 'echo:digest', 'Page views: 1 not in the digest (the crawler must not count): '.tail($digest));
        check((bool) preg_match('/Sign-up — jane@example\.com/u', $digest), 'echo:digest', 'the sign-up is not among the notable events: '.tail($digest));
        fact('Echo', trim((string) @file_get_contents("$logs/echo-demo.log"))."\n\necho:digest --dry-run:\n".$digest);
        break;

    default:
        annotate('error', 'check.php', 'unknown stage '.$stage);
        exit(2);
}

exit($errors > 0 ? 1 : 0);
