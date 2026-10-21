<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * One place for database CHECK constraints (DB review 2026-10, section 4.0). MySQL 8.0.16+ only:
 * SQLite cannot add a constraint to an existing table without a rebuild, so on SQLite these are
 * no-ops and the Action/validation guards are the test-time defence. Expressions must be valid
 * on MySQL; names must be unique per schema.
 */
final class SchemaChecks
{
    /**
     * @param  array<string, string>  $checks  constraint name => SQL expression
     */
    public static function add(string $table, array $checks): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($checks as $name => $expression) {
            DB::statement("alter table `{$table}` add constraint `{$name}` check ({$expression})");
        }
    }

    /**
     * @param  list<string>  $names
     */
    public static function drop(string $table, array $names): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($names as $name) {
            DB::statement("alter table `{$table}` drop check `{$name}`");
        }
    }
}
