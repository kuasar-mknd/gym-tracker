<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddBatchUuidColumnToActivityLogTable extends Migration
{
    public function up(): void
    {
        /** @var string|null $connection */
        $connection = config('activitylog.database_connection');
        /** @var string $table */
        $table = config('activitylog.table_name');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->uuid('batch_uuid')->nullable()->after('properties');
        });
    }

    public function down(): void
    {
        /** @var string|null $connection */
        $connection = config('activitylog.database_connection');
        /** @var string $table */
        $table = config('activitylog.table_name');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->dropColumn('batch_uuid');
        });
    }
}
