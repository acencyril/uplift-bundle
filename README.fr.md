# Uplift

**Des mises à jour de dépendances avec garde-fous, pour les projets Symfony.**

Uplift regarde ce qui peut bouger (PHP, Symfony, vos propres paquets, tous les autres),
mesure chaque montée (patch, mineure, majeure) et ne fait que ce que votre politique
autorise. Il réécrit `composer.json` quand une montée dépasse une contrainte
(`7.4.*` -> `8.0.*`, `^0.3` -> `^0.4`), lance `composer update`, Rector si vous l'avez,
puis vos tests, et écrit un rapport. Il ne pousse rien et ne déploie rien : il laisse
une branche et un rapport à une personne, un bac à sable ou la CI.

*English documentation: [README.md](README.md)*

```bash
composer require acencyril/uplift-bundle
php bin/console uplift:plan
```

---

## La politique

Dans l'environnement (`.env`, le serveur, la CI), pour que chaque serveur décide sans
changer le code :

| Variable | Valeurs | Défaut | |
|---|---|---|---|
| `UPLIFT_ENABLED` | `0`, `1` | `0` | à `0`, `uplift:apply` ne change rien (`uplift:plan` planifie quand même) |
| `UPLIFT_PHP` | `off`, `patch`, `minor`, `major` | `patch` | le PHP visé par le projet |
| `UPLIFT_SYMFONY` | idem | `patch` | les paquets `symfony/*` tenus au `X.Y.*` du framework |
| `UPLIFT_PACKAGES` | idem | `patch` | tous les autres paquets |
| `UPLIFT_PHP_MAX` | une branche, ex. `8.1` | aucun | un plafond strict pour PHP (voir plus bas) |
| `UPLIFT_OWN_VENDOR` | des vendors séparés par des virgules, ou `none` | `acencyril` | les paquets que vous publiez vous-même |
| `UPLIFT_TEST_COMMAND` | une commande shell | `vendor/bin/phpunit` | ce qui dit que la mise à jour marche ; vide : pas de tests |
| `UPLIFT_ADOPT`, `UPLIFT_ADOPT_SKIP` | voir [Les nouveautés](#les-nouveautés-pas-seulement-les-dépréciations) | `propose` | |

Un niveau est la plus grande montée faite automatiquement. Au-delà, la montée est
**proposée** dans le rapport et attend une personne. Avec les défauts, les patchs sont
appliqués et toute mineure ou majeure est seulement proposée. Une valeur fausse
(`UPLIFT_PHP=all`) est une erreur, jamais un défaut silencieux.

### Vos propres paquets

Les paquets de `UPLIFT_OWN_VENDOR` montent d'eux-mêmes **jusqu'à une mineure**, quoi
que dise `UPLIFT_PACKAGES` (plus loin si `UPLIFT_PACKAGES` autorise plus). Avant 1.0,
la mineure est le niveau qui casse (`0.3` -> `0.4`) : vos paquets 0.x suivent donc
leurs versions jusqu'au bout, puisque vous écrivez les deux côtés et les publiez
ensemble. À partir de 1.0, une majeure casse volontairement une API stable : elle
attend que quelqu'un lise ses notes de mise à jour. `UPLIFT_OWN_VENDOR=none` les traite
comme les autres.

### Un plafond PHP

Certains serveurs ne peuvent pas dépasser une version de PHP : le système
d'exploitation la fournit, ou le serveur d'applications ne supporte rien de plus
récent. Mettez cette branche dans `UPLIFT_PHP_MAX` :

- PHP ne monte jamais au-dessus, quoi que dise `UPLIFT_PHP` ;
- une version de n'importe quel paquet qui demande un PHP au-dessus du plafond est
  **bloquée**, et le rapport le dit : `requires PHP >=8.2, above UPLIFT_PHP_MAX=8.1`.

Uplift respecte aussi `config.platform.php` : les paquets sont vérifiés contre le PHP
pour lequel le projet est construit, après la montée de PHP du même passage. Une
version qui demande un PHP plus récent (mais sous le plafond) est bloquée avec
`requires PHP >=8.4, the project is on 8.3.27 (the proposed PHP move to 8.4 would allow it)`.

### Une marche à la fois

Uplift avance comme les guides de mise à jour : une marche par passage, pour PHP, pour
Symfony et pour chaque paquet. La marche suivante vient au passage suivant (la nuit
suivante, le mois suivant), et seulement quand celle-ci a passé ses tests, puis a été
validée quand [Rehearsal](https://github.com/acencyril/rehearsal-bundle) est en mode
manuel.

- **Les majeures, une par passage.** Avant une montée majeure, un paquet passe à la
  dernière mineure de sa majeure actuelle : c'est là que toutes les dépréciations de la
  majeure suivante apparaissent. De là seulement, il monte à la dernière version de la
  majeure **suivante**, jamais plus loin. Pour Symfony, c'est sa LTS : un projet en 6.2
  passe d'abord en 6.4, puis en 7.x, puis en 8.x (`7.4` -> `8.1` convient : 8.1 est une
  mineure de la majeure suivante).
- **Avant 1.0**, chaque mineure est une ligne à part : `0.3` -> `0.4` -> `0.5`.
- **PHP, une branche par passage :** `8.1` -> `8.2` -> `8.3`, jamais `8.1` -> `8.4`.
  Le plafond (`UPLIFT_PHP_MAX`) s'applique toujours.

La politique dit toujours jusqu'où la marche de ce passage peut aller ; une marche
qu'elle n'autorise pas est proposée. Le plan et le rapport montrent tout le chemin
restant, pour voir le retard du projet :

```
symfony/*: 7.3.5 -> 7.4.8 now; then 8.1.2 in later runs
```

## Les commandes

### `uplift:plan`

Lit `composer.json`, `composer.lock`, `composer outdated --direct`, Packagist et
php.net, puis liste chaque montée avec sa décision :

| Décision | Sens |
|---|---|
| `apply` | autorisée par la politique : `uplift:apply` la fait |
| `propose` | possible, mais au-delà de la politique : une personne décide |
| `blocked` | impossible ici ; la raison dit pourquoi |

Pour PHP, Symfony et chaque dépendance directe, au plus trois lignes, toutes dans la
marche de ce passage (voir [Une marche à la fois](#une-marche-à-la-fois)) : la plus
grande montée autorisée, une plus grande à proposer, une plus grande encore qui est
bloquée. Puis le chemin restant : les marches des passages suivants.

```bash
php bin/console uplift:plan                     # un tableau
php bin/console uplift:plan --format=markdown   # ou json
php bin/console uplift:plan --outdated=outdated.json   # un composer outdated enregistré
```

### `uplift:apply`

Fait les montées `apply` :

1. réécrit `composer.json` là où une montée dépasse sa contrainte, dans la forme où
   elle était écrite (`7.4.*` -> `8.0.*`, `^0.3` -> `^0.4`, `~2.13.1` -> `~3.0.2`,
   `^6.4 || ^7.0` -> `^6.4 || ^7.0 || ^8.0`), la ligne Symfony
   (`extra.symfony.require`), et pour PHP `config.platform.php` et le plancher
   `require.php`. Seules ces valeurs changent : rien n'est réordonné ni réindenté ;
2. lance `composer update` pour les seuls paquets qui montent, chacun épinglé à sa
   cible (`--with vendor/paquet:1.2.3 --with-dependencies`). Si composer ne peut pas
   tout résoudre ensemble, les deux fichiers sont remis en état et les montées qui
   gardent leur contrainte sont essayées seules ; si ça échoue aussi, rien ne change ;
3. lance Rector (`vendor/bin/rector process`) si le projet a un `rector.php`
   (`--no-rector` pour s'en passer) ;
4. lance `UPLIFT_TEST_COMMAND` ;
5. écrit `var/uplift/report.json` et `var/uplift/report.md`.

```bash
php bin/console uplift:apply --branch=upgrade/2026-11 --commit --rehearsal=.rehearsal/changes.json
```

| Option | |
|---|---|
| `--branch=NOM` | crée cette branche et travaille dessus |
| `--commit` | y commite le résultat (il faut un arbre de travail propre) |
| `--rehearsal=FICHIER` | ajoute aussi le passage comme un changement dans un fichier de changements [Rehearsal](https://github.com/acencyril/rehearsal-bundle), avec un `summary` que Rehearsal 0.2+ affiche en carte : le mouvement principal, les paquets montés (d'après composer.lock), les contraintes réécrites, les tests après la montée, ce que Rector a réécrit, les mouvements refusés par la politique et la suite. Les tests ne sont pas lancés avant la montée : la carte n'a pas d'« avant » |
| `--report-dir=DOSSIER` | où vont les rapports |
| `--outdated=FICHIER`, `--no-outdated` | un `composer outdated` enregistré, ou aucun |
| `--no-rector` | sans Rector |
| `--adopt` | puis cherche les nouveautés des versions installées (voir [Les nouveautés](#les-nouveautés-pas-seulement-les-dépréciations)) |

Code de sortie 1 si composer a échoué, si les tests ont échoué ou si le passage a été
refusé ; 0 sinon (y compris « désactivé » et « rien à faire »).

La suite vous appartient : pousser la branche depuis la CI, ouvrir une demande de
fusion, construire un bac à sable, essayer le changement dans votre admin avec
Rehearsal.

## Les nouveautés, pas seulement les dépréciations

Monter les versions et corriger les dépréciations garde un site en marche. Une version
apporte aussi des fonctionnalités que le site pourrait maintenant utiliser : du code
écrit à la main avant qu'elles existent. `uplift:adopt` le trouve.

Une **adoption** est une nouveauté d'une version de paquet (ou de PHP) : son paquet et
sa première version, un titre et une courte explication (traductions
`adoption.<id>.title` et `adoption.<id>.summary`, domaine `uplift`, en anglais et en
français), un lien vers la documentation officielle, un détecteur, et une règle Rector
quand une règle fait la réécriture (sinon le changement est manuel). Seules les
adoptions permises par les versions installées sont cherchées (`composer.lock`, et pour
PHP la version visée par le projet) ; les autres sont listées *plus tard*.

La détection est statique : le code est analysé (nikic/php-parser), jamais chargé ni
exécuté. Chaque candidat est rapporté en `fichier:ligne`, avec la ligne elle-même et ce
qu'on peut y mettre.

| Adoption | Demande | Trouve | Réécriture |
|---|---|---|---|
| `map-request` | `symfony/http-kernel` >= 6.3 | dans les actions de contrôleur, `json_decode($request->getContent())` et `$request->toArray()` (`#[MapRequestPayload]`), `$request->query->get('page')`, `->getInt('page')`… (`#[MapQueryParameter]`), `$request->query->all()` (`#[MapQueryString]`) | manuelle : la classe cible est à écrire |
| `as-command` | `symfony/console` >= 5.3 | les commandes qui déclarent `protected static $defaultName` ou `$defaultDescription` (dépréciés depuis 6.1) sans `#[AsCommand]` | Rector `CommandPropertyToAttributeRector` |
| `readonly-promotion` | PHP >= 8.1 | les propriétés promues et typées du constructeur jamais réécrites : privées, ou dans une classe finale ; pas dans les entités Doctrine | Rector `ReadOnlyPropertyRector` |
| `lock-attribute` | à confirmer | les contrôleurs et listeners qui appellent `createLock()` puis `acquire()`/`release()` | aucune, **expérimentale** |

`lock-attribute` est un exemple d'adoption pour une fonctionnalité **non confirmée** :
un attribut de verrou sur les actions de contrôleur (l'idée :
`#[Lock('invoice-{id}', blocking: true, ttl: 10)]`). Symfony 7.4 n'a pas d'attribut de
ce genre ; son paquet, sa version et son API sont provisoires jusqu'à ce qu'une version
le publie. Elle est marquée *expérimentale* et jamais appliquée.

```bash
php bin/console uplift:adopt                      # la liste, avec les endroits candidats
php bin/console uplift:adopt --apply              # réécrit ce que la politique autorise
php bin/console uplift:adopt --apply --rehearsal=.rehearsal/changes.json --locale=fr
```

Avec `--apply` et `UPLIFT_ADOPT=apply`, chaque adoption qui a une règle Rector est
faite seule : Rector tourne sur ses fichiers candidats uniquement (une configuration
écrite pour l'occasion, Rector 1.0 ou plus récent), puis `UPLIFT_TEST_COMMAND`. Si les
tests échouent (ou Rector), ses fichiers sont remis comme avant, et l'adoption suivante
est tentée. Le résultat de chacune (`applied`, `reverted`, `rector_failed`,
`unchanged`, `untested`) va dans le rapport.

| Option | |
|---|---|
| `--apply` | réécrit avec Rector, puis les tests décident |
| `--path=DIR` | ce qui est analysé (répétable ; défaut : le réglage `adopt_paths`, `src`) |
| `--rehearsal=FICHIER` | un changement [Rehearsal](https://github.com/acencyril/rehearsal-bundle) par adoption (`uplift-adopt-<id>`), à valider ou rejeter un par un ; les adoptions apparaissent aussi sur la carte de résumé du dernier changement de montée de version |
| `--report-dir=DIR` | où vont les rapports |
| `--locale=en\|fr` | la langue des titres et des explications |

Les adoptions sont écrites dans les mêmes `report.json` (clé `adoptions`) et
`report.md` (section « New features ») qu'`uplift:apply`.

### La politique

| Variable | Valeurs | Défaut | |
|---|---|---|---|
| `UPLIFT_ADOPT` | `off`, `propose`, `apply` | `propose` | `off` : pas cherchées ; `propose` : listées seulement ; `apply` : `--apply` réécrit celles qui ont une règle Rector |
| `UPLIFT_ADOPT_SKIP` | des ids d'adoption, séparés par des virgules | aucun | les adoptions dont vous ne voulez pas, ex. `readonly-promotion` |

Les adoptions manuelles et expérimentales sont seulement proposées.

### Dans le passage mensuel

`uplift:apply --adopt` cherche les nouveautés juste après la montée de versions : un
seul passage monte les versions, corrige les dépréciations (Rector avec votre
`rector.php`) et propose les nouveautés. Les adoptions ne sont réécrites qu'après une
montée dont les tests passent (et avec `UPLIFT_ADOPT=apply`) ; avec `--commit`, elles
ont leur propre commit. Quand rien n'a bougé, elles sont seulement proposées ; quand la
montée a échoué, elles ne sont pas cherchées.

### Vos propres adoptions

Implémentez `Acencyril\UpliftBundle\Adopt\Adoption` (une classe par nouveauté). Dans une
application avec l'autoconfiguration, la classe reçoit le tag `uplift.adoption` toute
seule ; dans un bundle, taguez le service. Ses textes vont dans
`translations/uplift.<locale>.*` :

```php
final class MyAdoption implements Adoption
{
    public function id(): string { return 'my-feature'; }
    public function package(): string { return 'vendor/package'; }
    public function minVersion(): string { return '2.1'; }
    public function docs(): string { return 'https://…'; }
    public function rector(): ?string { return null; }        // ou une classe de règle Rector
    public function experimental(): bool { return false; }
    public function detect(SourceFile $file): array { /* $file->ast(), $file->candidate($node, 'indice') */ }
}
```

## Configuration

Seulement où sont les choses ; ce qui peut bouger, c'est la politique ci-dessus.

```yaml
# config/packages/uplift.yaml
uplift:
    report_dir: '%kernel.project_dir%/var/uplift'
    composer: 'composer'
    project_dir: '%kernel.project_dir%'
    packagist_url: 'https://repo.packagist.org'
    php_releases_url: 'https://www.php.net'
    adopt_paths:                   # où uplift:adopt cherche
        - 'src'
```

Avec Symfony Flex, la recette enregistre le bundle, écrit ce fichier et ajoute les
variables `UPLIFT_*` à `.env` avec les défauts prudents. Sans Flex :

```php
// config/bundles.php
Acencyril\UpliftBundle\UpliftBundle::class => ['all' => true],
```

## En CI

Uplift ne pousse pas : c'est le workflow qui pousse. Par exemple, une fois par mois :

```yaml
name: uplift
on:
    schedule:
        -
            cron: '17 4 1 * *'
jobs:
    upgrade:
        runs-on: ubuntu-latest
        permissions:
            contents: write
        env:
            UPLIFT_ENABLED: '1'
        steps:
            -
                uses: actions/checkout@v4
            -
                uses: shivammathur/setup-php@v2
                with:
                    php-version: '8.3'
            -
                run: composer install --no-interaction
            -
                run: |
                    git config user.name "uplift"
                    git config user.email "uplift@users.noreply.github.com"
                    php bin/console uplift:apply --branch="upgrade/$(date +%Y-%m)" --commit --adopt
            -
                if: always()
                run: git push origin HEAD || true
```

## Les projets anciens

Uplift demande PHP 8.1 ou plus et Symfony 6.4 ou plus : Composer refuse de l'installer
sur PHP 5 ou 7, ou à côté de Symfony 4 ou 5. C'est voulu. Rattraper plusieurs majeures
est un chantier pour une personne : chaque majeure retire ce que la précédente avait
déprécié, et les changements de code qu'il faut, Rector ne les couvre qu'en partie.

Le chemin à la main, une majeure à la fois, chacune avec ses tests :

1. Symfony 4.4 -> 5.4 : corriger les dépréciations signalées par 4.4, monter PHP à ce
   que demande 5.4 (7.2.5 ou plus ; 8.1 facilite l'étape suivante), passer en 5.4,
   corriger ses dépréciations ;
2. Symfony 5.4 -> 6.4, avec PHP 8.1 ou plus ;
3. puis `composer require acencyril/uplift-bundle`.

À partir de là, Uplift fait avancer le projet, une marche par passage.

## Ce qu'il ne fait pas

- Il fait monter les dépendances directes. Les dépendances indirectes suivent autant
  que les directes en ont besoin (`--with-dependencies`) ; pour un passage de Symfony,
  tous les paquets `symfony/*` verrouillés sur l'ancienne ligne suivent aussi, requis
  directement ou non.
- Il ne change pas votre environnement d'exécution : une montée de PHP change
  `composer.json` ; votre image Docker ou votre serveur doit suivre. Le rapport le dit
  quand les étapes ont tourné sur un autre PHP.
- Les contraintes qu'il ne sait pas transposer (`>=1.0 <2.0`, des branches) vous
  reviennent ; la montée est bloquée avec la raison.
- Les pré-versions ne sont jamais candidates.

## Tests

Packagist et php.net sont derrière deux interfaces (`ReleaseSource`,
`PhpReleaseSource`), et les commandes derrière `Runner` : les tests utilisent des
`composer.json` / `composer.lock` de démonstration, des listes de versions fixes et un
faux exécuteur. Aucun accès réseau.

```bash
vendor/bin/phpunit
```

## Licence

MIT
