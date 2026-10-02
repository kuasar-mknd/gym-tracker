#!/bin/bash
# Vérifie au démarrage que le dossier des sauvegardes est inscriptible par
# l'utilisateur du conteneur (#1812) : en production, c'est un dossier de l'hôte
# que le dépôt ne tient pas, et quand il ne l'était pas, aucune archive ne
# s'écrivait sans que rien ne le dise au démarrage.
#
# Le script AVERTIT seulement : il rend 1 et écrit sur stderr, et
# entrypoint.sh l'appelle dans un `if` pour que l'application démarre même
# si le partage est cassé. Chaque accès au partage est borné par `timeout` :
# un partage endormi ne retient pas le démarrage. Pas de `set -e` : le
# message doit sortir même quand une commande échoue.
#
# Usage : verifier-sauvegardes.sh [dossier]   (défaut /app/storage/app/sauvegardes)

set -u

dossier="${1:-/app/storage/app/sauvegardes}"
sonde="$dossier/.sonde-demarrage-$$"

# La racine se crée si elle manque, comme la sauvegarde le ferait ; puis une
# vraie écriture, parce qu'un test de droits répond côté client sur un partage réseau.
# shellcheck disable=SC2016 # "$1" et "$2" sont ceux du sh lancé par timeout.
timeout 10 sh -c 'mkdir -p "$1" && : > "$2" && rm -f "$2"' verifier-sauvegardes "$dossier" "$sonde" 2>/dev/null
code=$?

if [ "$code" -eq 0 ]; then
    exit 0
fi

if [ "$code" -eq 124 ]; then
    cause="le partage n'a pas répondu en 10 s"
    remede="vérifier que le partage BACKUP_HOST_PATH est monté et répond"
else
    cause="dossier $(timeout 5 stat -c '%u:%g, mode %a' "$dossier" 2>/dev/null || echo 'introuvable ou illisible')"
    remede="donner le dossier BACKUP_HOST_PATH à l'uid 33 (chown -R 33:33)"
fi

echo "ATTENTION : le dossier des sauvegardes $dossier n'est pas inscriptible par uid $(id -u):gid $(id -g) ($cause) : aucune sauvegarde ne pourra s'écrire (#1812). Sur le serveur, $remede ; le contrôle « Dossier des sauvegardes » de la page « Santé » passe au vert dans les cinq minutes, sans redémarrage." >&2

exit 1
