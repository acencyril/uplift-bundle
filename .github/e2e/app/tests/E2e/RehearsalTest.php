<?php

namespace App\Tests\E2e;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Rehearsal section, after uplift:apply --rehearsal: signed in as the in-memory
 * admin (HTTP basic), the Uplift change is listed with its tests, validated, and the
 * decision is recorded. Facts for the run's annotations go to var/e2e/rehearsal.json.
 */
final class RehearsalTest extends WebTestCase
{
    public function testAnonymousIsAskedToSignIn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/en/admin/rehearsal');

        self::assertResponseStatusCodeSame(401);
    }

    public function testWrongPasswordIsRefused(): void
    {
        $client = static::createClient([], ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'wrong']);
        $client->request('GET', '/en/admin/rehearsal');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheUpliftChangeIsValidated(): void
    {
        $client = static::createClient([], ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'e2e-admin']);
        $crawler = $client->request('GET', '/en/admin/rehearsal');
        self::assertResponseIsSuccessful();

        $listed = $crawler->filter('article.rh-change')->each(static fn ($a) => [
            'id' => substr((string) $a->attr('id'), \strlen('change-')),
            'title' => trim($a->filter('h3')->text()),
            'state' => trim($a->filter('.rh-change-head .rh-pill')->text()),
            'tests' => $a->filter('.rh-pill[class*="is-passed"], .rh-pill[class*="is-failed"], .rh-pill[class*="is-not_run"]')->count() > 0
                ? trim($a->filter('.rh-pill[class*="is-passed"], .rh-pill[class*="is-failed"], .rh-pill[class*="is-not_run"]')->last()->text()) : '',
        ]);
        $uplift = array_values(array_filter($listed, static fn (array $c) => 1 === preg_match('/^uplift-\d{4}-\d{2}-\d{2}$/', $c['id'])));
        self::assertCount(1, $uplift, 'the Uplift change is listed: '.json_encode($listed));
        $id = $uplift[0]['id'];
        $article = $crawler->filter('#change-'.$id);
        $text = $article->text();
        self::assertStringContainsString('Dependency upgrades', $text);
        self::assertMatchesRegularExpression('#symfony/\* 7\.3\.\d+ -> 7\.4\.\d+ \(minor\)#', $text);
        self::assertSame('To decide', $uplift[0]['state']);
        self::assertSame('Tests passed', $uplift[0]['tests']);

        $form = $article->selectButton('validate')->form(['comment' => 'Tried on the e2e copy: home page and admin work.']);
        $client->submit($form);
        self::assertResponseRedirects();
        $after = $client->followRedirect();
        $flash = $after->filter('.rh-flash')->count() ? trim($after->filter('.rh-flash')->text()) : '';
        $state = trim($after->filter('#change-'.$id.' .rh-change-head .rh-pill')->text());
        self::assertSame('Validated', $state);
        self::assertStringContainsString('admin', $after->filter('#change-'.$id.' .rh-decision')->text());

        $file = static::getContainer()->getParameter('kernel.project_dir').'/var/rehearsal/decisions.json';
        self::assertFileExists($file);
        $decisions = (string) file_get_contents($file);
        self::assertStringContainsString($id, $decisions);
        self::assertStringContainsString('validated', $decisions);

        @mkdir(\dirname($file, 2).'/e2e', 0o775, true);
        file_put_contents(\dirname($file, 2).'/e2e/rehearsal.json', json_encode([
            'listed' => $listed,
            'validated' => $id,
            'flash' => $flash,
            'state_after' => $state,
            'decisions_file' => json_decode($decisions, true),
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }
}
