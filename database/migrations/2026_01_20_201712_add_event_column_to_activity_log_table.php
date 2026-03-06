<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddEventColumnToActivityLogTable extends Migration
{
    public function up(): void
    {
        /** @var string|null $connection */
        $connection = config('activitylog.database_connection');
        /** @var string $table */
        $table = config('activitylog.table_name');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->string('event')->nullable()->after('subject_type');
        });
    }

    public function down(): void
    {
        /** @var string|null $connection */
        $connection = config('activitylog.database_connection');
        /** @var string $table */
        $table = config('activitylog.table_name');

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->dropColumn('event');
        });
    }
}
