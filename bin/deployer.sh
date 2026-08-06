#!/bin/sh
#
# Bascule une version déjà transférée dans `releases/<sha>`.
#
# Lancé par le workflow GitHub Actions, mais utilisable à la main :
#   CHEMIN_BASE=/var/www/datan sh releases/<sha>/bin/deployer.sh <sha>
#
# Le principe : tout ce qui est coûteux ou risqué se fait dans la nouvelle
# version, *avant* de basculer le lien. Si une étape échoue, le site continue
# de servir l'ancienne — un déploiement raté ne se voit pas de l'extérieur.

set -eu

SHA="${1:?usage: deployer.sh <sha>}"
BASE="${CHEMIN_BASE:?CHEMIN_BASE non défini}"
VERSION="$BASE/releases/$SHA"
PARTAGE="$BASE/shared"
GARDER=5

cd "$VERSION"

echo "→ Rattachement des répertoires partagés"
# Ce qui doit survivre à un déploiement : les secrets, les journaux, et surtout
# les clones Tricoteuses (1,8 Go) — les recloner à chaque mise en ligne serait
# absurde, et le fichier .datan-modifies qu'ils portent est l'état du delta.
ln -sfn "$PARTAGE/.env.local" "$VERSION/.env.local"
mkdir -p "$VERSION/var"
ln -sfn "$PARTAGE/log" "$VERSION/var/log"
ln -sfn "$PARTAGE/tricoteuses" "$VERSION/var/tricoteuses"

echo "→ Migrations"
# Avant la bascule : une colonne qui manque casse la page, une colonne en trop
# ne gêne pas. Les migrations passent donc pendant que l'ancienne version sert.
APP_ENV=prod php bin/console doctrine:migrations:migrate --no-interaction --no-ansi --allow-no-migration

echo "→ Compilation des assets"
APP_ENV=prod php bin/console asset-map:compile --no-ansi

echo "→ Préchauffage du conteneur"
# Indispensable, et pas seulement pour la vitesse : le conteneur compilé fige le
# nombre d'arguments de chaque constructeur. Servir un code neuf avec un cache
# ancien fait tomber les pages en ArgumentCountError (CLAUDE.md).
APP_ENV=prod php bin/console cache:warmup --no-ansi

echo "→ Bascule du lien"
ln -sfn "$VERSION" "$BASE/current.nouveau"
mv -Tf "$BASE/current.nouveau" "$BASE/current"

echo "→ Rechargement de PHP-FPM"
# Rechargement et non redémarrage : les requêtes en cours vont au bout. Sans
# lui, OPcache continue de servir l'ancien code depuis le chemin résolu.
sudo systemctl reload php8.3-fpm

echo "→ Purge des versions anciennes (on garde les $GARDER dernières)"
cd "$BASE/releases"
ls -1t | tail -n "+$((GARDER + 1))" | while read -r vieille; do
    [ "$vieille" = "$SHA" ] && continue
    rm -rf "$BASE/releases/$vieille"
done

echo "✓ En ligne : $SHA"
