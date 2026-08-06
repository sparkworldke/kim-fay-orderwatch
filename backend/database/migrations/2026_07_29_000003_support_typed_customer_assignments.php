<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand uniqueness to (user, customer, assignment_type) so owner + servicing
 * can coexist for the same customer/user pair.
 *
 * MySQL note: the old unique (user_id, customer_acumatica_id) often backs the
 * user_id foreign key. Dropping it fails with errno 1553 unless a dedicated
 * user_id index exists first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_customer_assignments')) {
            return;
        }

        // Already migrated?
        if ($this->indexExists('user_customer_assignments', 'user_customer_assignment_typed_unique')) {
            return;
        }

        // Give the user_id FK its own index before removing the composite unique.
        if (! $this->indexExists('user_customer_assignments', 'uca_user_id_fk_idx')) {
            Schema::table('user_customer_assignments', function (Blueprint $table) {
                $table->index('user_id', 'uca_user_id_fk_idx');
            });
        }

        // Drop legacy unique — try Laravel name first, then discover actual name on MySQL.
        $legacyUnique = $this->findUniqueIndexOnColumns(
            'user_customer_assignments',
            ['user_id', 'customer_acumatica_id'],
        );

        if ($legacyUnique !== null) {
            Schema::table('user_customer_assignments', function (Blueprint $table) use ($legacyUnique) {
                $table->dropUnique($legacyUnique);
            });
        }

        Schema::table('user_customer_assignments', function (Blueprint $table) {
            $table->unique(
                ['user_id', 'customer_acumatica_id', 'assignment_type'],
                'user_customer_assignment_typed_unique',
            );
            if (! $this->indexExists('user_customer_assignments', 'customer_assignment_type_index')) {
                $table->index(
                    ['customer_acumatica_id', 'assignment_type'],
                    'customer_assignment_type_index',
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_customer_assignments')) {
            return;
        }

        Schema::table('user_customer_assignments', function (Blueprint $table) {
            if ($this->indexExists('user_customer_assignments', 'user_customer_assignment_typed_unique')) {
                $table->dropUnique('user_customer_assignment_typed_unique');
            }
            if ($this->indexExists('user_customer_assignments', 'customer_assignment_type_index')) {
                $table->dropIndex('customer_assignment_type_index');
            }
        });

        // Cannot restore (user_id, customer) unique if owner+servicing pairs exist.
        $dupes = DB::table('user_customer_assignments')
            ->select('user_id', 'customer_acumatica_id', DB::raw('COUNT(*) as c'))
            ->groupBy('user_id', 'customer_acumatica_id')
            ->having('c', '>', 1)
            ->exists();

        if (! $dupes) {
            Schema::table('user_customer_assignments', function (Blueprint $table) {
                $table->unique(['user_id', 'customer_acumatica_id']);
            });
        }

        if ($this->indexExists('user_customer_assignments', 'uca_user_id_fk_idx')) {
            Schema::table('user_customer_assignments', function (Blueprint $table) {
                $table->dropIndex('uca_user_id_fk_idx');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? '') : ($row['name'] ?? '');
                if ($name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        // MySQL / MariaDB
        $database = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $indexName],
        );

        return $row !== null;
    }

    /**
     * @param  list<string>  $columns  exact ordered column list for a unique index
     * @return string|list<string>|null  name for dropUnique(), or column array
     */
    private function findUniqueIndexOnColumns(string $table, array $columns): string|array|null
    {
        $driver = Schema::getConnection()->getDriverName();

        // Default Laravel name
        $laravelName = $table.'_'.implode('_', $columns).'_unique';
        if ($this->indexExists($table, $laravelName)) {
            return $laravelName;
        }

        if ($driver === 'sqlite') {
            // Fall back to column list; SQLite Schema builder accepts it
            return $columns;
        }

        $database = Schema::getConnection()->getDatabaseName();
        $indexes = DB::select(
            'SELECT index_name, column_name, seq_in_index, non_unique
             FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND non_unique = 0
             ORDER BY index_name, seq_in_index',
            [$database, $table],
        );

        $grouped = [];
        foreach ($indexes as $row) {
            $name = $row->index_name ?? $row->INDEX_NAME ?? null;
            $col = $row->column_name ?? $row->COLUMN_NAME ?? null;
            if ($name === null || $col === null || strcasecmp((string) $name, 'PRIMARY') === 0) {
                continue;
            }
            $grouped[$name][] = (string) $col;
        }

        foreach ($grouped as $name => $cols) {
            if ($cols === $columns) {
                return $name;
            }
        }

        // Last resort: try dropping by columns
        return $columns;
    }
};
