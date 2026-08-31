<?php

declare(strict_types=1);

/**
 * Une migration qui supprime doit d'abord demander si la chose existe.
 *
 * Le 31/08, `drop_is_pr_from_sets_table` a interrompu un deploiement de
 * production : `sets.is_pr` n'est cree par AUCUNE migration — il vient du dump
 * `database/schema/mysql-schema.sql`, que seule une base construite a neuf
 * charge. Une base migree pas a pas ne l'a jamais eue, et la suppression a
 * echoue, laissant les quinze migrations suivantes non appliquees.
 *
 * Le controle est statique, sur le TEXTE des migrations : un test qui
 * migrerait vraiment sur un schema divergent devrait fabriquer ce schema, donc
 * connaitre a l'avance la divergence qu'il cherche.
 */
it('demande si la chose existe avant de la supprimer', function (): void {
    $manquantes = [];

    $fichiers = glob(database_path('migrations/*.php'));

    expect($fichiers)->not->toBeFalse();

    foreach (is_array($fichiers) ? $fichiers : [] as $chemin) {
        $source = (string) file_get_contents($chemin);
        $debut = mb_strpos($source, 'public function up(): void');
        $fin = mb_strpos($source, 'public function down(): void');

        if ($debut === false) {
            continue;
        }

        $up = $fin !== false && $fin > $debut
            ? mb_substr($source, $debut, $fin - $debut)
            : mb_substr($source, $debut);

        $supprime = preg_match('/->drop(Column|Index|Unique)\(/', $up) === 1;
        $demande = preg_match('/Schema::has(Column|Index|Table)\(/', $up) === 1;

        if ($supprime && ! $demande) {
            $manquantes[] = basename($chemin);
        }
    }

    expect($manquantes)->toBe([]);
});

/**
 * Et une migration qui AJOUTE doit pouvoir être rejouée : une migration qui
 * echoue en son milieu n'est pas enregistree, et sa reprise retomberait sur
 * l'index qu'elle vient de poser.
 */
it('demande si l’index existe avant de le poser', function (): void {
    $manquantes = [];

    $fichiers = glob(database_path('migrations/*.php'));

    expect($fichiers)->not->toBeFalse();

    foreach (is_array($fichiers) ? $fichiers : [] as $chemin) {
        $source = (string) file_get_contents($chemin);
        $debut = mb_strpos($source, 'public function up(): void');
        $fin = mb_strpos($source, 'public function down(): void');

        if ($debut === false) {
            continue;
        }

        $up = $fin !== false && $fin > $debut
            ? mb_substr($source, $debut, $fin - $debut)
            : mb_substr($source, $debut);

        // Seules les migrations qui posent un index sur une table EXISTANTE
        // sont concernées : `Schema::create()` part d'une table vide.
        if (! str_contains($up, 'Schema::table(')) {
            continue;
        }

        $pose = preg_match('/->(index|unique)\(/', $up) === 1;
        $demande = str_contains($up, 'Schema::hasIndex(');

        if ($pose && ! $demande) {
            $manquantes[] = basename($chemin);
        }
    }

    expect($manquantes)->toBe([]);
});

/**
 * Ajouter et supprimer un index dans le MEME `Schema::table()` est un piege.
 *
 * Les deux `Schema::has*` d'une fermeture sont evalues avant qu'aucun ordre ne
 * parte : le second lit donc l'etat d'AVANT le premier. Or ajouter un index qui
 * peut servir une contrainte de clef etrangere fait disparaitre celui qu'InnoDB
 * avait cree pour elle — le controle voit alors un index que l'ajout vient de
 * supprimer, et le `DROP` echoue en 1091.
 *
 * C'est ce qui a interrompu un second deploiement de production le 31/08, apres
 * que le premier eut ete corrige. La separation en deux appels suffit : le
 * second controle lit l'etat d'apres.
 */
it('ne pose pas et ne retire pas un index dans le même appel', function (): void {
    $melangees = [];
    $fichiers = glob(database_path('migrations/*.php'));

    expect($fichiers)->not->toBeFalse();

    foreach (is_array($fichiers) ? $fichiers : [] as $chemin) {
        $source = (string) file_get_contents($chemin);

        // Le corps de chaque fermeture passee a `Schema::table()`, isole par
        // comptage d'accolades — une expression reguliere ne sait pas equilibrer.
        $depart = 0;

        while (($ouverture = mb_strpos($source, 'Schema::table(', $depart)) !== false) {
            $accolade = mb_strpos($source, '{', $ouverture);

            if ($accolade === false) {
                break;
            }

            $niveau = 0;
            $fin = $accolade;

            for ($i = $accolade, $n = mb_strlen($source); $i < $n; $i++) {
                $c = mb_substr($source, $i, 1);
                $niveau += $c === '{' ? 1 : ($c === '}' ? -1 : 0);

                if ($niveau === 0) {
                    $fin = $i;
                    break;
                }
            }

            $corps = mb_substr($source, $accolade, $fin - $accolade);
            $pose = preg_match('/->(index|unique)\(/', $corps) === 1;
            $retire = preg_match('/->drop(Index|Unique)\(/', $corps) === 1;

            if ($pose && $retire) {
                $melangees[] = basename($chemin);
            }

            $depart = $fin > $ouverture ? $fin : $ouverture + 1;
        }
    }

    expect(array_values(array_unique($melangees)))->toBe([]);
});
