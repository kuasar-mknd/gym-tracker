<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the database refuse a duplicate the application cannot see coming.
 *
 * A create the server accepted and wrote, whose response never reached the
 * client, is indistinguishable from one that never arrived — both look like a
 * network error. The client queues it and replays it, and a second row appears.
 * Workout 8 is the shape of it: five lines stamped within two seconds of each
 * other by a queue flush, two of them the same exercise.
 *
 * The unique index is the point. Looking the key up in PHP first is a comfort,
 * not a guarantee — two replays can both read "absent" in the same instant.
 * Only the index settles that race; the actions catch its violation and return
 * whichever write won.
 *
 * Nullable because every existing row, and every write that never goes through
 * the offline queue, has no key — and a unique index admits any number of NULLs.
 */
return new class() extends Migration
{
    /**
     * Scoped to the parent, not global.
     *
     * The key reaches us in a client-controlled header, and the lookup that
     * consumes it is deliberately scoped to the already-authorised parent so
     * that replaying someone else's key cannot return their row. A global
     * unique index would contradict that scope: the lookup would rightly find
     * nothing, the insert would then collide with a stranger's key, and an
     * honest write would fail. It would also hand anyone a way to burn keys for
     * everybody. Unique per parent is exactly as much uniqueness as the lookup
     * relies on.
     *
     * @var array<string, string>
     */
    private array $scopes = ['sets' => 'workout_line_id', 'workout_lines' => 'workout_id'];

    public function up(): void
    {
        foreach ($this->scopes as $table => $parent) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $parent): void {
                if (! Schema::hasColumn($table, 'idempotency_key')) {
                    $blueprint->string('idempotency_key', 64)->nullable();
                }

                if (! Schema::hasIndex($table, $table.'_'.$parent.'_idempotency_key_unique')) {
                    $blueprint->unique([$parent, 'idempotency_key']);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->scopes as $table => $parent) {
            Schema::table($table, function (Blueprint $blueprint) use ($parent): void {
                $blueprint->dropUnique([$parent, 'idempotency_key']);
                $blueprint->dropColumn('idempotency_key');
            });
        }
    }
};
