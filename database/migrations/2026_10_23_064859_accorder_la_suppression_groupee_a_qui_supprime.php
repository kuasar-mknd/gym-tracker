<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
 * La suppression groupée du panneau demande désormais `DeleteAny:…` (la
 * méthode `deleteAny()` de chaque policy), puis `delete()` pour chaque ligne
 * choisie (`authorizeIndividualRecords()`). Shield ne générait pas cette
 * permission jusqu'ici, et rien ne lance `shield:generate` au déploiement :
 * sans elle, un rôle qui supprime une ligne (`Delete:X`) ne supprimerait pas
 * en groupe.
 *
 * Chaque rôle qui porte `Delete:X`, et chaque administrateur qui la tient en
 * direct, reçoit donc `DeleteAny:X`, sur la même garde : il supprime en
 * groupe ce qu'il supprime ligne à ligne, et rien de plus. Un rôle qui ne
 * supprime rien ne reçoit rien. Le super administrateur tient déjà ces
 * capacités par la liste de `AppServiceProvider`.
 *
 * Rejouable : une permission ou une attribution déjà là n'est pas réécrite.
 * `down()` ne retire rien : après la montée, rien ne distingue une permission
 * accordée ici de la même accordée depuis le panneau.
 */
return new class() extends Migration
{
    /**
     * Les modèles des ressources du panneau dont la policy lit `DeleteAny:X`.
     *
     * @var list<string>
     */
    private const array MODELES = [
        'User', 'Workout', 'Goal', 'Exercise', 'Supplement', 'Achievement', 'Role', 'ExceptionEnregistree', 'ErreurNavigateur',
    ];

    public function up(): void
    {
        foreach (['permissions', 'role_has_permissions', 'model_has_permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        foreach (self::MODELES as $modele) {
            $suppressions = DB::table('permissions')->where('name', "Delete:{$modele}")->get(['id', 'guard_name']);

            foreach ($suppressions as $suppression) {
                $id = filter_var($suppression->id ?? null, FILTER_VALIDATE_INT);
                $garde = $suppression->guard_name ?? null;

                if ($id !== false && is_string($garde)) {
                    $this->accorderLaSuppressionGroupee($modele, $id, $garde);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        //
    }

    /**
     * Donne `DeleteAny:X` à chaque rôle et à chaque administrateur qui tient
     * `Delete:X` (la permission `$suppression`, sur `$garde`).
     */
    private function accorderLaSuppressionGroupee(string $modele, int $suppression, string $garde): void
    {
        $roles = DB::table('role_has_permissions')->where('permission_id', $suppression)->pluck('role_id');
        $directs = DB::table('model_has_permissions')->where('permission_id', $suppression)->get(['model_type', 'model_id']);

        if ($roles->isEmpty() && $directs->isEmpty()) {
            return;
        }

        $maintenant = now();
        DB::table('permissions')->insertOrIgnore([
            'name' => "DeleteAny:{$modele}",
            'guard_name' => $garde,
            'created_at' => $maintenant,
            'updated_at' => $maintenant,
        ]);
        $groupee = filter_var(DB::table('permissions')->where('name', "DeleteAny:{$modele}")->where('guard_name', $garde)->value('id'), FILTER_VALIDATE_INT);

        if ($groupee === false) {
            return;
        }

        DB::table('role_has_permissions')->insertOrIgnore($roles->map(static fn (mixed $role): array => [
            'permission_id' => $groupee,
            'role_id' => $role,
        ])->all());

        DB::table('model_has_permissions')->insertOrIgnore($directs->map(static fn (object $direct): array => [
            'permission_id' => $groupee,
            'model_type' => $direct->model_type,
            'model_id' => $direct->model_id,
        ])->all());
    }
};
