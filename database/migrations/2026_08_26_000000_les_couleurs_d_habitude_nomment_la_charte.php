<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `habits.color` contient une classe CSS, et c'est par la que les couleurs
 * brutes revenaient : la base elle-meme stockait `bg-blue-500`. Aucune charte
 * ne peut tenir tant qu'une valeur d'affichage est persistee hors de son
 * registre — la convertir dans le code aurait laisse les lignes existantes
 * pointer vers des classes qui n'existent plus, donc des pastilles sans
 * couleur.
 *
 * Les seize valeurs deviennent des noms de jetons. Le sens de chaque choix est
 * conserve : qui avait pris « Emeraude » garde un vert distinct de « Vert »,
 * ce qu'une conversion vers les roles de la charte aurait perdu.
 *
 * Que la couleur soit stockee comme une classe reste discutable — un index de
 * palette serait plus juste. C'est note dans #1580 ; cette migration ne fait
 * que ramener la valeur dans la charte.
 */
return new class() extends Migration
{
    /**
     * @var array<string, string>
     */
    private const CORRESPONDANCE = [
        'bg-slate-500' => 'bg-palette-ardoise',
        'bg-red-500' => 'bg-palette-rouge',
        'bg-orange-500' => 'bg-palette-orange',
        'bg-amber-500' => 'bg-palette-ambre',
        'bg-green-500' => 'bg-palette-vert',
        'bg-emerald-500' => 'bg-palette-emeraude',
        'bg-teal-500' => 'bg-palette-turquoise',
        'bg-cyan-500' => 'bg-palette-cyan',
        'bg-sky-500' => 'bg-palette-ciel',
        'bg-blue-500' => 'bg-palette-bleu',
        'bg-indigo-500' => 'bg-palette-indigo',
        'bg-violet-500' => 'bg-palette-violet',
        'bg-purple-500' => 'bg-palette-pourpre',
        'bg-fuchsia-500' => 'bg-palette-fuchsia',
        'bg-pink-500' => 'bg-palette-rose',
        'bg-rose-500' => 'bg-palette-framboise',
    ];

    public function up(): void
    {
        foreach (self::CORRESPONDANCE as $ancienne => $jeton) {
            DB::table('habits')->where('color', $ancienne)->update(['color' => $jeton]);
        }

        /*
         * Le defaut vit AUSSI dans le schema — `DEFAULT 'bg-slate-500'`. Ne
         * migrer que les lignes aurait laisse la prochaine habitude creee sans
         * ce champ repartir avec une classe qui n'existe plus.
         */
        Schema::table('habits', function (Blueprint $table): void {
            $table->string('color')->default('bg-palette-ardoise')->change();
        });
    }

    public function down(): void
    {
        Schema::table('habits', function (Blueprint $table): void {
            $table->string('color')->default('bg-slate-500')->change();
        });

        foreach (self::CORRESPONDANCE as $ancienne => $jeton) {
            DB::table('habits')->where('color', $jeton)->update(['color' => $ancienne]);
        }
    }
};
