# Publication d'une version

## Publication d'un tag

Ce tag ne publie rien tant que tout n'est pas vert. Sur le commit exact du tag, l'image n'est poussée que si :

1. les tests backend, frontend et navigateur, le lint, l'audit et la construction multi-architecture sont verts ;
2. l'image démarre sur une base MySQL vide, en 8.4 comme en 9.7.4, et répond sur `/up` (job `demarrage`) ;
3. la **passe nocturne** est verte, chacune de ses parts, seuils de mutation compris (job `promotion`).

La release GitHub exige en plus la CI entière, `semgrep`, `secrets` et `workflows` compris.

La passe nocturne est planifiée à 03h17 UTC, mais GitHub la lance plusieurs heures plus tard (entre 07h42 et 10h31 UTC du 1er septembre au 5 octobre 2026), sur la pointe de `main` au moment où elle part. Un tag est donc couvert par la prochaine passe planifiée si son commit est encore la pointe de `main` quand elle part : l'attendre, puis relancer la promotion. Pour ne pas attendre, ou si `main` a avancé, la lancer sur le tag lui-même :

```bash
gh run list --workflow mutation.yml --commit <sha>   # une passe couvre-t-elle déjà ce commit ?
gh workflow run mutation.yml --ref v1.2.3
```

Une fois la nuit verte, rien ne relance la publication tout seul : relancer les jobs échoués du run de CI du tag (`gh run rerun <id> --failed`), ce qui rejoue `promotion`, puis le run de `release.yml`.

Un échec sur `main` — CI ou passe nocturne — **ouvre automatiquement une issue**, dédupliquée par workflow. Un run annulé aussi, par la limite de temps d'une part ou à la main : il ne compte pas comme vert pour la promotion. Seul le run de CI remplacé par un push plus récent avant d'avoir commencé n'en ouvre pas : il n'a rien testé.
