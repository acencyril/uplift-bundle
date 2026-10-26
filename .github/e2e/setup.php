<?php

// Registers the three bundles (if Flex did not) and adds their recipes' env variables
// to the app's .env, as Flex would with their recipes. Usage: php setup.php <app> <workspace>

[, $app, $ws] = $argv;
$bundles = require $app.'/config/bundles.php';
$env = (string) file_get_contents($app.'/.env');
foreach (['uplift', 'rehearsal', 'echo'] as $b) {
    $manifest = json_decode((string) file_get_contents("$ws/$b-bundle/recipe/manifest.json"), true);
    foreach (array_keys($manifest['bundles'] ?? []) as $class) {
        $bundles[$class] ??= ['all' => true];
    }
    $block = '';
    foreach ($manifest['env'] ?? [] as $k => $v) {
        if (str_starts_with($k, '#')) {
            $block .= '# '.$v."\n";
        } elseif (!preg_match('/^'.preg_quote($k, '/').'=/m', $env)) {
            $block .= $k.'='.$v."\n";
        }
    }
    if ('' !== $block) {
        $env .= "\n###> acencyril/$b-bundle ###\n".$block."###< acencyril/$b-bundle ###\n";
    }
}
// SQLite for the e2e app; the mailer goes nowhere.
$env = preg_replace('/^DATABASE_URL=.*$/m', 'DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"', $env, -1, $n);
if (0 === $n) {
    $env .= "DATABASE_URL=\"sqlite:///%kernel.project_dir%/var/data.db\"\n";
}
if (!preg_match('/^MAILER_DSN=/m', $env)) {
    $env .= "MAILER_DSN=null://null\n";
}
file_put_contents($app.'/.env', $env);

$out = "<?php\n\nreturn [\n";
foreach ($bundles as $class => $envs) {
    $pairs = [];
    foreach ($envs as $e => $on) {
        $pairs[] = var_export($e, true).' => '.var_export($on, true);
    }
    $out .= '    '.$class.'::class => ['.implode(', ', $pairs)."],\n";
}
file_put_contents($app.'/config/bundles.php', $out."];\n");
