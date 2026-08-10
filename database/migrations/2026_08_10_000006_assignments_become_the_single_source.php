<?php

use App\Models\ProductRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who owns what was stored twice: seven columns on product_requests and a row in
 * product_request_assignments. Every ownership check read the columns; the table
 * only carried the task and deadline. Two copies of one fact, kept in step by
 * convention.
 *
 * The table becomes the only answer, and gains history: instead of overwriting a
 * row on handover, the old one is closed with ended_at and a new one opened. That
 * is what makes "how long does the photographer actually take" answerable.
 *
 * Order matters — backfill every column into the table before dropping any of
 * them, or live assignments are lost.
 */
return new class extends Migration
{
    /** Column on product_requests => role key in the assignments table. */
    private const COLUMNS = [
        'brand_manager_id'  => 'brand_manager_id',
        'assigned_to'       => 'assigned_to',
        'supply_chain_id'   => 'supply_chain_id',
        'photographer_id'   => 'photographer_id',
        'image_editor_id'   => 'image_editor_id',
        'content_owner_id'  => 'content_owner_id',
        'qa_owner_id'       => 'qa_owner_id',
    ];

    public function up(): void
    {
        // Each step is guarded: MySQL DDL is not transactional, so a mid-way
        // failure leaves earlier statements applied and this has to be re-runnable.
        if (!Schema::hasColumn('product_request_assignments', 'ended_at')) {
            Schema::table('product_request_assignments', function (Blueprint $table) {
                // Null = the live assignment. Set = handed over or cleared.
                $table->timestamp('ended_at')->nullable()->after('completed_at');
            });
        }

        // Create the replacement index first. MySQL will not drop the unique one
        // while it is the only index serving the product_request_id foreign key,
        // and this composite starts with that column so it can take over.
        if (!$this->indexExists('pra_current_owner_index')) {
            Schema::table('product_request_assignments', function (Blueprint $table) {
                $table->index(['product_request_id', 'role', 'ended_at'], 'pra_current_owner_index');
            });
        }

        // A role can appear more than once per request now, so the old uniqueness
        // has to go.
        if ($this->indexExists('product_request_assignments_product_request_id_role_unique')) {
            Schema::table('product_request_assignments', function (Blueprint $table) {
                $table->dropUnique('product_request_assignments_product_request_id_role_unique');
            });
        }

        $this->backfill();

        foreach (array_keys(self::COLUMNS) as $column) {
            if (Schema::hasColumn('product_requests', $column)) {
                Schema::table('product_requests', function (Blueprint $table) use ($column) {
                    $table->dropConstrainedForeignId($column);
                });
            }
        }
    }

    /** Driver-agnostic: information_schema would only work on MySQL. */
    private function indexExists(string $name): bool
    {
        foreach (Schema::getIndexes('product_request_assignments') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /** Every owner column becomes a live assignment row, if it isn't one already. */
    private function backfill(): void
    {
        $now = now();

        DB::table('product_requests')
            ->select(array_merge(['id'], array_keys(self::COLUMNS)))
            ->orderBy('id')
            ->chunk(200, function ($requests) use ($now) {
                $rows = [];

                foreach ($requests as $request) {
                    foreach (self::COLUMNS as $column => $role) {
                        $userId = $request->{$column} ?? null;

                        if (!$userId) {
                            continue;
                        }

                        $exists = DB::table('product_request_assignments')
                            ->where('product_request_id', $request->id)
                            ->where('role', $role)
                            ->whereNull('ended_at')
                            ->exists();

                        if ($exists) {
                            continue;   // the table already knows about this one
                        }

                        $rows[] = [
                            'product_request_id' => $request->id,
                            'role'               => $role,
                            'user_id'            => $userId,
                            'title'              => ProductRequest::taskForRole($role),
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ];
                    }
                }

                if ($rows) {
                    DB::table('product_request_assignments')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::table('product_requests', function (Blueprint $table) {
            foreach (array_keys(self::COLUMNS) as $column) {
                $table->foreignId($column)->nullable()->constrained('users')->nullOnDelete();
            }
        });

        // Put the live owners back so a rollback is not a data loss.
        DB::table('product_request_assignments')
            ->whereNull('ended_at')
            ->orderBy('id')
            ->chunk(500, function ($assignments) {
                foreach ($assignments as $assignment) {
                    if (!array_key_exists($assignment->role, self::COLUMNS)) {
                        continue;
                    }

                    DB::table('product_requests')
                        ->where('id', $assignment->product_request_id)
                        ->update([$assignment->role => $assignment->user_id]);
                }
            });

        Schema::table('product_request_assignments', function (Blueprint $table) {
            $table->dropIndex('pra_current_owner_index');
            $table->dropColumn('ended_at');
            $table->unique(['product_request_id', 'role']);
        });
    }
};
