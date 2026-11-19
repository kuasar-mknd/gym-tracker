# Publier une version

Cette page dit ce qu'il faut pour qu'un tag `v*` publie l'image, et que faire quand il ne la publie pas.

## Publication d'un tag

### Conditions

Sur le commit exact du tag, l'image n'est poussée que si les trois conditions sont remplies :

1. Les tests backend, frontend et navigateur, le lint, l'audit et la construction multi-architecture sont verts.
2. L'image démarre sur une base MySQL vide, en 8.4 comme en 9.7.4, et répond sur `/up` (job `demarrage`).
3. La **passe nocturne** est verte, chacune de ses parts, seuils de mutation compris (job `promotion`).

La release GitHub exige en plus la CI entière, `semgrep`, `secrets` et `workflows` compris.

### Quand la passe nocturne n'est pas verte pour le tag

La passe nocturne est planifiée à 03h17 UTC. GitHub la lance plusieurs heures plus tard, sur la pointe de `main` au moment où elle part. Un tag est donc couvert par la prochaine passe planifiée si son commit est encore la pointe de `main` quand elle part.

Deux façons de débloquer le tag :

| Situation | Action |
| --- | --- |
| Le commit du tag est encore la pointe de `main` | Attendre la prochaine passe planifiée, puis relancer la promotion (étape suivante). |
| Ne pas attendre, ou `main` a avancé | Lancer la passe sur le tag lui-même, puis relancer la promotion. |

Pour savoir si une passe couvre déjà le commit, et pour lancer la passe sur le tag :

```bash
gh run list --workflow mutation.yml --commit <sha>   # une passe couvre-t-elle déjà ce commit ?
gh workflow run mutation.yml --ref v1.2.3
```

### Relancer la publication

Une fois la nuit verte, rien ne relance la publication tout seul.

1. Relancer les jobs échoués du run de CI du tag, ce qui rejoue `promotion` :

   ```bash
   gh run rerun <id> --failed
   ```

2. Relancer le run de `release.yml`.

## Échec sur `main`

Un échec sur `main`, de la CI ou de la passe nocturne, **ouvre automatiquement une issue**, dédupliquée par workflow.

- Un run annulé en ouvre une aussi, qu'il l'ait été par la limite de temps ou à la main : il ne compte pas comme vert pour la promotion.
- Seul le run de CI remplacé par un push plus récent avant d'avoir commencé n'en ouvre pas : il n'a rien testé.
