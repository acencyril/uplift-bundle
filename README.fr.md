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

## Les commandes

### `uplift:plan`

Lit `composer.json`, `composer.lock`, `composer outdated --direct`, Packagist et
php.net, puis liste chaque montée avec sa décision :

| Décision | Sens |
|---|---|
| `apply` | autorisée par la politique : `uplift:apply` la fait |
| `propose` | possible, mais au-delà de la politique : une personne décide |
| `blocked` | impossible ici ; la raison dit pourquoi |

Pour PHP, Symfony et chaque dépendance directe, au plus trois lignes : la plus grande
montée autorisée, une plus grande à proposer, une plus grande encore qui est bloquée.

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
| `--rehearsal=FICHIER` | ajoute aussi le passage comme un changement dans un fichier de changements [Rehearsal](https://github.com/acencyril/rehearsal-bundle) |
| `--report-dir=DOSSIER` | où vont les rapports |
| `--outdated=FICHIER`, `--no-outdated` | un `composer outdated` enregistré, ou aucun |
| `--no-rector` | sans Rector |

Code de sortie 1 si composer a échoué, si les tests ont échoué ou si le passage a été
refusé ; 0 sinon (y compris « désactivé » et « rien à faire »).

La suite vous appartient : pousser la branche depuis la CI, ouvrir une demande de
fusion, construire un bac à sable, essayer le changement dans votre admin avec
Rehearsal.

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
                    php bin/console uplift:apply --branch="upgrade/$(date +%Y-%m)" --commit
            -
                if: always()
                run: git push origin HEAD || true
```

## Ce qu'il ne fait pas

- Il fait monter les dépendances directes. Les dépendances indirectes suivent autant
  que les directes en ont besoin (`--with-dependencies`).
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
