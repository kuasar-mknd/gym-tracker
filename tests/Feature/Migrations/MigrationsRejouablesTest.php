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
