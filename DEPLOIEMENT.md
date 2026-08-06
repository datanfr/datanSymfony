# Mise en production

Cible retenue : **VPS nu** (Debian 12 ou Ubuntu 24.04), nginx + PHP-FPM,
préproduction sur **datan.remikel.fr** avant toute bascule de `datan.fr`.
La base se constitue par **restauration d'un dump**, puis les imports prennent
le relais.

`CRON.md` tient la planification, `TODO.md` §2 la liste de ce qui reste à faire
le jour J. Ce fichier-ci dit comment monter la machine et y poser le code.

---

## 0. À corriger dans le dépôt, avant tout

Trois choses bloquent aujourd'hui un déploiement, et aucune ne se répare depuis
le serveur.

- [ ] **`APP_SECRET` est vide** dans `.env`. Il signe les cookies de session et
      les jetons CSRF : vide, la connexion et les formulaires de la rédaction ne
      tiennent pas. Il se pose dans `.env.local` **sur le serveur**, jamais dans
      `.env` qui est suivi par Git.
      ```bash
      php -r 'echo bin2hex(random_bytes(32)), "\n";'
      ```
- [ ] **Deux migrations ne sont pas commitées** — `Version20260805090000.php` et
      `Version20260805120000.php`. Elles sont appliquées sur la base de
      développement, donc invisibles ici, mais le serveur ne les aura pas.
- [ ] **`compose.yaml` décrit un PostgreSQL** hérité du squelette Symfony, alors
      que le projet tourne sur MariaDB. Il ne sert à rien en production, mais il
      ment à quiconque le lit — à supprimer ou à réécrire.

Et une vérification : `.env` porte `APP_ENV=prod`. C'est voulu, mais cela veut
dire qu'un poste de développement a besoin d'un `.env.local` pour repasser en
`dev` — et que le serveur, lui, n'a rien à redire à ce sujet.

---

## 1. Préalables serveur

### Dimensionnement

| Poste | Taille | Pourquoi |
| --- | --- | --- |
| Base | 1,71 Go | 6,7 M lignes, dont 2,46 M votes (`vote` pèse 844 Mo à elle seule) |
| Clones Tricoteuses | 1,8 Go | `var/tricoteuses/`, neuf dépôts Git |
| Code + `vendor` + assets | ~600 Mo | |
| Marge de restauration | ~2 Go | MariaDB écrit son journal pendant le rechargement |

**40 Go de disque et 4 Go de RAM** sont un socle confortable. En dessous de
2 Go de RAM, les imports volumineux (3,1 M de résultats électoraux) deviennent
inconfortables.

### Paquets

```bash
sudo apt update && sudo apt install -y \
  nginx git curl unzip \
  php8.3-fpm php8.3-cli php8.3-mysqli php8.3-intl php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-opcache
```

Deux pièges :

- **`php8.3-mysqli`, pas `pdo_mysql`.** Le DSN du projet est `mysqli://`. Avec
  la seule extension PDO, la connexion échoue au premier appel.
- **`git` est une dépendance d'exécution**, pas seulement de développement :
  `Moissonneur.php` lance le binaire pour moissonner les Tricoteuses, qui ne
  publient pas d'API HTTP.

### MariaDB 11.7

Debian 12 livre MariaDB 10.11. Le DSN annonce `serverVersion=11.7.2-MariaDB` et
il ne faut pas le mentir : une version déclarée fausse fait générer à Doctrine
des diffs de schéma fantômes. Passer par le dépôt officiel :

```bash
curl -LsS https://r.mariadb.com/downloads/mariadb_repo_setup | sudo bash -s -- --mariadb-server-version=11.7
sudo apt update && sudo apt install -y mariadb-server mariadb-client
sudo mariadb-secure-installation
```

Puis la base et son compte :

```sql
CREATE DATABASE datan_symfony CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'datan'@'localhost' IDENTIFIED BY '<mot de passe long>';
GRANT ALL PRIVILEGES ON datan_symfony.* TO 'datan'@'localhost';
FLUSH PRIVILEGES;
```

### Arborescence

```bash
sudo mkdir -p /var/www/datan/{releases,shared/log,shared/tricoteuses}
sudo chown -R deploy:www-data /var/www/datan
```

Le déploiement écrit dans `releases/<sha>` et bascule `current` par lien
symbolique. `shared/` porte ce qui doit survivre : `.env.local`, les journaux et
les 1,8 Go de clones Tricoteuses.

---

## 2. Base de données

### a. Dump depuis le poste de développement

À faire **après** avoir appliqué toutes les migrations en local, pour que la
table `doctrine_migration_versions` parte cohérente avec le schéma — sinon le
serveur rejouera des migrations déjà présentes et échouera.

```powershell
mariadb-dump -h 127.0.0.1 -P 3307 -u datan -pdatan `
  --single-transaction --quick --default-character-set=utf8mb4 `
  --routines --events datan_symfony | gzip > datan_symfony.sql.gz
```

`--single-transaction` évite de verrouiller les tables, `--quick` de charger
844 Mo de votes en mémoire.

### b. Transfert et restauration

```bash
scp datan_symfony.sql.gz deploy@datan.remikel.fr:/tmp/
ssh deploy@datan.remikel.fr
zcat /tmp/datan_symfony.sql.gz | mariadb -u datan -p datan_symfony
rm /tmp/datan_symfony.sql.gz
```

Contrôle immédiat — les trois nombres doivent correspondre à ceux du poste :

```sql
SELECT (SELECT COUNT(*) FROM vote)         AS votes,          -- 2 464 905
       (SELECT COUNT(*) FROM vote_groupe)  AS ventilations,   --   196 195
       (SELECT COUNT(*) FROM decryptage)   AS decryptages;
```

### c. Clones Tricoteuses

Le dump apporte les données, pas les dépôts. Sans eux, le premier
`app:sync:quotidien` reclone tout (1,8 Go) au lieu de lire un delta.

```bash
cd /var/www/datan/current
APP_ENV=prod php bin/console app:tricoteuses:sync --liste     # ce qui sera cloné
APP_ENV=prod php bin/console app:tricoteuses:sync             # ~1,8 Go, une fois
```

Les dépôts figés (`scrutins-xiv`, `-xv`, `-xvi`, `dossiers-xv`, `dossiers-xvi`)
sont **hors moisson nocturne** : ils ne se clonent que si on les demande
nommément. Le dump contenant déjà leurs données, ce n'est utile que pour rejouer
leurs imports plus tard.

### d. Imports de récupération, contre la vraie base

C'est le point le plus délicat, et il est déjà listé en `TODO.md` §2. Le backup
public `datan.fr/assets/dataset_backup` est **réduit et anonymisé** : un seul
compte, `users_mp` vide. La base de développement en porte donc les traces. À
rejouer contre la base de production réelle, **avant l'ouverture** :

```bash
APP_ENV=prod php bin/console app:import:utilisateurs   # comptes + mots de passe repris tels quels
APP_ENV=prod php bin/console app:import:decryptages    # la vraie base en a plus que les 250 du backup
APP_ENV=prod php bin/console app:import:articles
APP_ENV=prod php bin/console app:import:professions-foi  # avec la copie de assets/data/professions/
```

Puis purger les comptes d'essai `redaction`, `editeur`, `guibert`.

> **Un import de récupération écrase les écritures locales.** Une fois
> l'application devenue la source — un rédacteur qui publie, un député qui écrit
> —, le rejouer détruit leur travail. Aucun n'entre dans une tâche planifiée.

### e. Sauvegarde, dès le premier jour

Le décryptage éditorial ne se régénère pas. Une entrée cron, avant même
l'ouverture :

```cron
30 3 * * * mariadb-dump -u datan -p<mdp> --single-transaction --quick datan_symfony \
  | gzip > /var/backups/datan/$(date +\%Y-\%m-\%d).sql.gz && \
  find /var/backups/datan -name '*.sql.gz' -mtime +30 -delete
```

---

## 3. Git

Le dépôt est `git@github.com:datanfr/datanSymfony.git`, branche `master`.

- [ ] **Committer le travail en cours.** `git status` montre aujourd'hui une
      vingtaine de fichiers modifiés et cinq non suivis, dont les deux migrations
      du §0. Rien ne se déploie tant que `master` ne les porte pas.
- [ ] **Ne jamais committer `.env.local`.** Il est déjà dans `.gitignore` ; c'est
      lui qui portera `APP_SECRET`, le mot de passe MariaDB, `ANTHROPIC_API_KEY`
      et le `MAILER_DSN` Mailjet.
- [ ] **Clé de déploiement.** Générer une paire dédiée sur le poste, poser la
      publique dans `~/.ssh/authorized_keys` de l'utilisateur `deploy` du
      serveur, la privée dans les secrets GitHub :
      ```bash
      ssh-keygen -t ed25519 -C "deploiement datan" -f ~/.ssh/datan_deploy -N ""
      ssh-keyscan -p 22 datan.remikel.fr                # pour SSH_HOTE_CLE_PUBLIQUE
      ```
- [ ] **Protéger `master`** : exiger que le workflow *Qualité* passe avant fusion.
      C'est le seul garde-fou, le dépôt n'ayant aucun test.

---

## 4. GitHub Actions

Deux workflows sont posés dans `.github/workflows/`.

### `qualite.yml` — à chaque push et pull request

Syntaxe PHP, gabarits Twig, YAML, conteneur de services, puis **les 45
migrations rejouées sur une base MariaDB 11.7 vierge** suivies de
`doctrine:schema:validate`. C'est ce dernier contrôle qui compte : sans test
unitaire, c'est la seule chose qui attrape une entité modifiée sans migration —
défaut qui, sinon, ne se manifeste qu'en production par une page en erreur.

### `deploiement.yml` — sur push vers `master`

`composer install --no-dev --optimize-autoloader`, transfert rsync vers
`releases/<sha>`, puis `bin/deployer.sh` sur le serveur : migrations,
`asset-map:compile`, `cache:warmup`, bascule du lien symbolique, rechargement de
PHP-FPM, purge des versions au-delà des cinq dernières. Le workflow finit par
interroger l'URL publique et échoue si elle ne répond pas 200.

L'ordre n'est pas indifférent : **tout se fait dans la nouvelle version avant la
bascule**. Une étape qui échoue laisse le site servir l'ancienne, et le
déploiement raté ne se voit pas de l'extérieur.

C'est aussi ce qui rend le déploiement compatible avec le sync de 6 h. Vider
`var/cache/prod` sous une commande en cours la fait échouer sans message
exploitable ; avec un cache par version, la commande en vol garde le sien.

### Secrets et variables à créer

| Nom | Type | Valeur |
| --- | --- | --- |
| `SSH_CLE_PRIVEE` | secret | contenu de `~/.ssh/datan_deploy` |
| `SSH_HOTE` | secret | `datan.remikel.fr` |
| `SSH_UTILISATEUR` | secret | `deploy` |
| `SSH_HOTE_CLE_PUBLIQUE` | secret | sortie de `ssh-keyscan` |
| `CHEMIN_BASE` | variable | `/var/www/datan` |
| `URL_PUBLIQUE` | variable | `https://datan.remikel.fr` |
| `SSH_PORT` | variable | facultatif, 22 par défaut |

À créer dans deux *environments* GitHub, `preproduction` et `production` : un
push ne déploie qu'en préproduction, la production se déclenche à la main par
`workflow_dispatch`. Poser une règle d'approbation sur l'environnement
`production` pour que la bascule reste un geste délibéré.

### Une permission sudo à ouvrir

`deployer.sh` recharge PHP-FPM. Sans mot de passe :

```sudoers
deploy ALL=(root) NOPASSWD: /bin/systemctl reload php8.3-fpm
```

---

## 5. Cron

`CRON.md` porte le détail et les justifications. À l'installation, trois entrées
suffisent — en adaptant `/var/www/datan` en `/var/www/datan/current` :

```cron
# Quotidien — 6 h. Une seule entrée : l'ordre est garanti par &&, et la purge du
# cache n'a lieu que si tout ce qui précède a réussi.
0 6 * * * cd /var/www/datan/current && sh -c 'APP_ENV=prod php bin/console app:sync:quotidien --no-ansi \
  && APP_ENV=prod php bin/console app:import:commissions --no-ansi \
  && APP_ENV=prod php bin/console app:calcul:statistiques-deputes --no-ansi \
  && rm -rf var/cache/prod/http_cache' >> var/log/sync-quotidien.log 2>&1

# Hebdomadaire — le seul appel à assemblee-nationale.fr, hors chaîne à dessein.
15 4 * * 1 cd /var/www/datan/current && APP_ENV=prod php bin/console app:scraper:scrutins --no-ansi >> var/log/scraper-scrutins.log 2>&1

# Sauvegarde (cf. §2.e)
30 3 * * * ...
```

### Le worker Messenger n'est pas un cron

`SendEmailMessage` est routé vers le transport `async` (`doctrine://default`).
**Sans processus consommateur, aucun courriel ne part** — ni mot de passe
oublié, ni demande de compte député, ni inscription. C'est un service permanent :

```ini
# /etc/systemd/system/datan-messenger.service
[Unit]
Description=Datan — consommateur Messenger
After=network.target mariadb.service

[Service]
User=deploy
WorkingDirectory=/var/www/datan/current
Environment=APP_ENV=prod
ExecStart=/usr/bin/php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

`--time-limit` fait sortir le processus toutes les heures ; `Restart=always` le
relance, avec le code de la version courante. C'est ce qui évite qu'il continue
d'exécuter du code déployé la semaine dernière.

```bash
sudo systemctl enable --now datan-messenger
```

---

## 6. Autres

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name datan.remikel.fr;

    root /var/www/datan/current/public;

    ssl_certificate     /etc/letsencrypt/live/datan.remikel.fr/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/datan.remikel.fr/privkey.pem;

    # Préproduction : jamais d'indexation. Le référencement est le fonds de
    # commerce de datan.fr ; un miroir indexé lui ferait concurrence à
    # lui-même. À retirer le jour de la bascule sur datan.fr.
    add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
    auth_basic           "Préproduction";
    auth_basic_user_file /etc/nginx/.htpasswd-datan;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        internal;
    }

    location ~ \.php$ { return 404; }

    # Les assets d'AssetMapper portent un condensat dans leur nom : ils sont
    # immuables et peuvent se mettre en cache sans réserve.
    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    client_max_body_size 8m;
}

server {
    listen 80;
    server_name datan.remikel.fr;
    return 301 https://$host$request_uri;
}
```

`$realpath_root` et non `$document_root` : avec un déploiement par lien
symbolique, la seconde forme fait servir à OPcache le code de la version
précédente.

Certificat : `sudo certbot --nginx -d datan.remikel.fr`.

### `.env.local` sur le serveur

Dans `/var/www/datan/shared/.env.local`, en `chmod 600` :

```dotenv
APP_SECRET=<les 64 caractères générés au §0>
DATABASE_URL="mysqli://datan:<mdp>@127.0.0.1:3306/datan_symfony?serverVersion=11.7.2-MariaDB&charset=utf8mb4"
MAILER_DSN=<DSN Mailjet réel>
CORS_ALLOW_ORIGIN='^https://(datan\.remikel\.fr|datan\.fr)$'

# Suivi : à n'activer qu'en production, pas en préproduction — sinon les visites
# d'essai polluent les statistiques réelles.
MATOMO_URL=
GTM_ID=

IA_MODELE=
ANTHROPIC_API_KEY=
```

Le port passe de **3307 à 3306** : 3307 est une particularité du poste de
développement.

### Proxy de confiance

Derrière nginx, Symfony ne voit que `127.0.0.1` et croit servir en `http`. Les
URL absolues — canoniques, sitemaps, Open Graph — sortent alors en clair. À
ajouter dans `config/packages/framework.yaml` :

```yaml
framework:
    trusted_proxies: '127.0.0.1,REMOTE_ADDR'
    trusted_headers: ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port']
```

### OPcache

```ini
; /etc/php/8.3/fpm/conf.d/99-datan.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0     ; le déploiement recharge FPM, inutile de vérifier les dates
realpath_cache_size=4096K
realpath_cache_ttl=600
memory_limit=512M
```

`validate_timestamps=0` suppose que **tout** déploiement recharge FPM. C'est le
cas de `deployer.sh` ; ne pas modifier un fichier à la main sur le serveur en
espérant que ça se voie.

### Recette avant d'ouvrir

- [ ] Une page de vote sort en ~20 ms au second appel (en-tête `Age:` présent).
- [ ] `/sitemap.xml` et chaque sous-sitemap répondent 200, et les adresses
      qu'ils annoncent aussi — un sitemap qui promet des 404 coûte plus cher que
      le silence.
- [ ] Les redirections 301 du legacy fonctionnent (slugs de département : tester
      `francais-de-letranger`).
- [ ] Connexion, mot de passe oublié : le courriel **arrive** (worker Messenger).
- [ ] Espace de rédaction : créer et publier un décryptage d'essai, puis le
      supprimer.
- [ ] `app:sync:quotidien` passe en entier à la main avant d'être planifié.
- [ ] Comparer une dizaine de pages à datan.fr par capture d'écran rendue, pas
      par diff de texte.

### Le jour de la bascule

Le reste — `TODO.md` §2 — est à dérouler à ce moment-là : variables de suivi
réelles, transvasement des abonnés newsletter, redirections des anciennes API
(`api/tables`, `api/votes`, `api/exposes`) qu'API Platform ne reprend pas, et
retrait de l'`auth_basic` et du `X-Robots-Tag` de la préproduction.
