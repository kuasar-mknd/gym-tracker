<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings activity_log to the shape spatie/laravel-activitylog v5 reads.
 *
 * v4 kept two unrelated things in one `properties` column: the tracked
 * attribute changes the package writes itself (`attributes` and `old`), and
 * whatever the caller attached with withProperties(). v5 splits them, so
 * `attribute_changes` gets the former and `properties` keeps only the latter.
 *
 * The rows already on disk still carry the v4 layout, and v5 reads
 * `attribute_changes` — so without the backfill below every historical entry
 * would render as a change of nothing at all. The data is moved rather than
 * copied: leaving `attributes`/`old` in `properties` would show them again as
 * user-supplied custom data.
 *
 * `batch_uuid` goes with the batch system, which v5 dropped.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->json('attribute_changes')->nullable()->after('causer_id');
        });

        $this->splitProperties();

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropColumn('batch_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->uuid('batch_uuid')->nullable()->after('properties');
        });

        $this->mergeProperties();

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropColumn('attribute_changes');
        });
    }

    /**
     * Moves `attributes` and `old` out of properties and into their own column.
     *
     * Chunked by id rather than loaded at once: this table is append-only and
     * grows without a ceiling, so the one machine where the migration is slow
     * is also the one where it would run out of memory.
     */
    private function splitProperties(): void
    {
        DB::table('activity_log')
            ->whereNotNull('properties')
            ->orderBy('id')
            ->chunkById(500, function (\Illuminate\Support\Collection $rows): void {
                foreach ($rows as $row) {
                    $properties = json_decode((string) $row->properties, true);

                    if (! is_array($properties)) {
                        continue;
                    }

                    $changes = array_intersect_key($properties, array_flip(['attributes', 'old']));

                    if ($changes === []) {
                        continue;
                    }

                    $remaining = array_diff_key($properties, $changes);

                    DB::table('activity_log')->where('id', $row->id)->update([
                        'attribute_changes' => json_encode($changes),
                        'properties' => $remaining === [] ? null : json_encode($remaining),
                    ]);
                }
            });
    }

    /** Folds the changes back into properties, so down() restores the v4 layout. */
    private function mergeProperties(): void
    {
        DB::table('activity_log')
            ->whereNotNull('attribute_changes')
            ->orderBy('id')
            ->chunkById(500, function (\Illuminate\Support\Collection $rows): void {
                foreach ($rows as $row) {
                    $changes = json_decode((string) $row->attribute_changes, true);
                    $properties = json_decode((string) ($row->properties ?? '{}'), true);

                    if (! is_array($changes)) {
                        continue;
                    }

                    DB::table('activity_log')->where('id', $row->id)->update([
                        'properties' => json_encode(array_merge(is_array($properties) ? $properties : [], $changes)),
                    ]);
                }
            });
    }
};
