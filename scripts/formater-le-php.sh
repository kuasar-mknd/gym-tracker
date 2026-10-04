#!/usr/bin/env bash
# Formate avec Pint les fichiers PHP que lint-staged passe au crochet de commit,
# avec un PHP que le code du projet ne fait pas trébucher (#1928).
#
# Pint lit chaque fichier avec le PHP qui le lance : un PHP plus ancien que
# celui du projet échoue en erreur de syntaxe sur du code valide, et le crochet
# refusait alors tout commit. Le script prend donc, dans l'ordre :
#
#   1. le conteneur de Sail, s'il tourne pour ce dossier ;
#   2. le PHP de l'hôte, ou celui de PINT_PHP, s'il atteint la borne basse de
#      la contrainte « php » de composer.json ;
#   3. sinon, il s'arrête en échec en disant quoi faire. Jamais un succès sans
#      formatage : il laisserait passer du code non formaté.
#
# Les chemins absolus que lint-staged passe sont ramenés à la racine du dépôt :
# dans le conteneur, le dépôt n'est pas monté au même endroit que sur l'hôte.
#
# Variables : PINT_PHP (le PHP de l'hôte, défaut php), SAIL_DOCKER_BINARY et
# APP_SERVICE (défauts docker et laravel.test). Ces deux dernières ne sont lues
# que dans l'environnement : Sail les lit aussi dans .env, et ajoute les
# fichiers de SAIL_FILES, ce que le script ne fait pas. Un service renommé par
# .env n'est donc pas vu, et le commit est refusé avec le service cherché.
#
# Usage : scripts/formater-le-php.sh [options de Pint] [fichiers…]

set -u

racine="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$racine" || exit 1

for argument in "$@"; do
    shift
    set -- "$@" "${argument#"$racine"/}"
done

service="${APP_SERVICE:-laravel.test}"
sail_ailleurs=""

# Sail tourne-t-il pour ce dossier ? `docker compose ps` ne fait que lire,
# quand `sail ps` arrêterait un conteneur sorti en erreur.
#
# Compose nomme le projet d'après le nom du dossier, pas son chemin : deux
# copies du dépôt qui portent le même nom voient le même conteneur, et
# `sail bin pint` formaterait l'autre copie en rendant un succès. Le conteneur
# n'est donc retenu que si Compose l'a lancé depuis ce dossier, liens résolus
# (Compose peut avoir noté le chemin logique). Sinon, sail_ailleurs dit pourquoi.
sail_tourne() {
    local docker="${SAIL_DOCKER_BINARY:-docker}"
    local conteneur dossier

    command -v "$docker" > /dev/null 2>&1 || return 1
    conteneur="$("$docker" compose ps --status running --quiet "$service" 2> /dev/null | head -n 1)"
    [ -n "$conteneur" ] || return 1

    dossier="$("$docker" inspect --format '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$conteneur" 2> /dev/null)"

    # Un dossier vide se teste d'abord : `cd ""` resterait ici, et passerait.
    if [ -z "$dossier" ]; then
        sail_ailleurs="tourne, mais docker inspect ne dit pas pour quel dossier"
    elif [ "$(cd "$dossier" 2> /dev/null && pwd -P)" = "$racine" ]; then
        return 0
    else
        sail_ailleurs="tourne pour un autre dossier (« $dossier »), pas pour celui-ci"
    fi

    return 1
}

# Vrai si la version $1 atteint la version $2, composante par composante.
version_atteint() {
    awk -v version="$1" -v minimum="$2" 'BEGIN {
        split(version, v, ".")
        split(minimum, m, ".")
        for (i = 1; i <= 3; i++) {
            if (v[i] + 0 != m[i] + 0) {
                exit !(v[i] + 0 > m[i] + 0)
            }
        }
        exit 0
    }'
}

if sail_tourne; then
    exec ./vendor/bin/sail bin pint "$@"
fi

php="${PINT_PHP:-php}"
contrainte="$(sed -n 's/^[[:space:]]*"php":[[:space:]]*"\([^"]*\)".*/\1/p' composer.json | head -n 1)"
minimum="$(printf '%s' "$contrainte" | sed -n 's/^[^0-9]*\([0-9][0-9.]*\).*/\1/p')"
version="$("$php" -r 'echo PHP_VERSION;' 2> /dev/null)"

if [ -n "$minimum" ] && [ -n "$version" ] && version_atteint "$version" "$minimum"; then
    exec "$php" vendor/bin/pint "$@"
fi

if [ -z "$minimum" ]; then
    constat="la contrainte « php » de composer.json est illisible (« $contrainte »)"
elif [ -z "$version" ]; then
    constat="aucun PHP ne répond à « $php », quand le projet demande PHP $contrainte (composer.json)"
else
    constat="le PHP de l'hôte (« $php ») est en $version, quand le projet demande PHP $contrainte (composer.json)"
fi

if [ -n "$sail_ailleurs" ]; then
    etat_de_sail="le service « $service » que voit Compose $sail_ailleurs"
else
    etat_de_sail="Sail ne tourne pas pour ce dossier (service « $service »)"
fi

cat >&2 << MESSAGE
Pint n'a rien formaté : $etat_de_sail, et $constat.
Un PHP plus ancien que celui du projet échoue en erreur de syntaxe sur son code (#1928).

Lance Sail, puis recommence le commit :
    ./vendor/bin/sail up -d

Ou désigne un PHP assez récent :
    PINT_PHP=/chemin/vers/php git commit
MESSAGE

exit 1
