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
# APP_SERVICE (lues comme Sail les lit, défauts docker et laravel.test).
#
# Usage : scripts/formater-le-php.sh [options de Pint] [fichiers…]

set -u

racine="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$racine" || exit 1

for argument in "$@"; do
    shift
    set -- "$@" "${argument#"$racine"/}"
done

# Sail tourne-t-il pour ce dossier ? `docker compose ps` ne fait que lire,
# quand `sail ps` arrêterait un conteneur sorti en erreur.
sail_tourne() {
    local docker="${SAIL_DOCKER_BINARY:-docker}"

    command -v "$docker" > /dev/null 2>&1 || return 1
    [ -n "$("$docker" compose ps --status running --quiet "${APP_SERVICE:-laravel.test}" 2> /dev/null)" ]
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

cat >&2 << MESSAGE
Pint n'a rien formaté : Sail ne tourne pas, et $constat.
Un PHP plus ancien que celui du projet échoue en erreur de syntaxe sur son code (#1928).

Lance Sail, puis recommence le commit :
    ./vendor/bin/sail up -d

Ou désigne un PHP assez récent :
    PINT_PHP=/chemin/vers/php git commit
MESSAGE

exit 1
