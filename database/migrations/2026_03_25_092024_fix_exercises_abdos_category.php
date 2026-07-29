<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('exercises')
            ->where('category', 'Abdos')
            ->update(['category' => 'Abdominaux']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('exercises')
            ->where('category', 'Abdominaux')
            ->update(['category' => 'Abdos']); // Reverting back to Abdos if rolled back
    }
};
