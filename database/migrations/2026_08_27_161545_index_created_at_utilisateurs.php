<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasIndex('users', 'users_created_at_index')) {
                return;
            }

            // `users` ne portait que sa clef primaire, l'unicite du courriel et
            // `provider_id`. Le tableau de bord admin lit pourtant deux fois par
            // cycle sur `created_at` : les inscrits des sept derniers jours, et
            // les dix plus recents.
            $table->index('created_at', 'users_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_created_at_index');
        });
    }
};
