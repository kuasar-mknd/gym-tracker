<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateActivityLogTable extends Migration
{
    public function up(): void
    {
        /** @var string|null $connection */
        $connection = config('activitylog.database_connection');
        /** @var string $table */
        $table = config('activitylog.table_name');

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });
    }

    public function down(): void
    {
        /** @var string|null $connection */
        $connection = config('activitylog.database_connection');
        /** @var string $table */
        $table = config('activitylog.table_name');

        Schema::connection($connection)->dropIfExists($table);
    }
}
