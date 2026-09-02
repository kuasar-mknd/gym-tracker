<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sets', 'order')) {
            Schema::table('sets', function (Blueprint $table): void {
                $table->integer('order')->default(0)->after('workout_line_id');
            });
        }

        /*
         * Les series existantes tiennent deja un ordre : celui de leur
         * creation. Le recopier plutot que de tout laisser a zero evite de
         * demander a chaque utilisateur de reordonner une seance qu'il n'a pas
         * touchee — et evite le cas ou echanger deux zeros n'ecrit rien.
         */
        DB::statement(
            'update sets s
               join (
                   select id, row_number() over (partition by workout_line_id order by id) - 1 as rang
                     from sets
               ) r on r.id = s.id
                set s.`order` = r.rang'
        );

        if (Schema::hasIndex('sets', 'sets_workout_line_id_order_index')) {
            return;
        }

        Schema::table('sets', function (Blueprint $table): void {
            $table->index(['workout_line_id', 'order'], 'sets_workout_line_id_order_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('sets', 'sets_workout_line_id_order_index')) {
            Schema::table('sets', function (Blueprint $table): void {
                $table->dropIndex('sets_workout_line_id_order_index');
            });
        }

        if (! Schema::hasColumn('sets', 'order')) {
            return;
        }

        Schema::table('sets', function (Blueprint $table): void {
            $table->dropColumn('order');
        });
    }
};
