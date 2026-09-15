<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * D8 — writes the MySQL database as SQL (like mysqldump) with PHP only, so server backups
 * work on hosting without shell access.
 */
class DatabaseDumpService
{
    private const ROWS_PER_INSERT = 200;

    private const ROWS_PER_READ = 1000;

    /**
     * @return int number of tables written
     */
    public function dump(string $path, ?string $connection = null): int
    {
        $db = DB::connection($connection);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('Server backups need a MySQL or MariaDB database.');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The database file could not be created.');
        }

        $pdo = $db->getPdo();
        $tables = collect($db->select('SHOW FULL TABLES'))
            ->map(fn ($row) => array_values((array) $row))
            ->filter(fn ($row) => ($row[1] ?? 'BASE TABLE') === 'BASE TABLE')
            ->map(fn ($row) => (string) $row[0])
            ->values();

        try {
            fwrite($handle, '-- '.config('app.name').' database backup, '.now()->toDateTimeString()."\n");
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

            foreach ($tables as $table) {
                $quoted = '`'.str_replace('`', '``', $table).'`';
                $create = (array) $db->selectOne("SHOW CREATE TABLE {$quoted}");
                fwrite($handle, "DROP TABLE IF EXISTS {$quoted};\n".array_values($create)[1].";\n\n");

                $order = collect($db->select("SHOW KEYS FROM {$quoted} WHERE Key_name = 'PRIMARY'"))
                    ->sortBy('Seq_in_index')
                    ->map(fn ($key) => '`'.str_replace('`', '``', $key->Column_name).'`')
                    ->implode(', ');

                $offset = 0;
                do {
                    $rows = $db->select("SELECT * FROM {$quoted}".($order !== '' ? " ORDER BY {$order}" : '').' LIMIT '.self::ROWS_PER_READ.' OFFSET '.$offset);
                    foreach (array_chunk($rows, self::ROWS_PER_INSERT) as $chunk) {
                        $columns = implode(', ', array_map(fn ($column) => '`'.str_replace('`', '``', $column).'`', array_keys((array) $chunk[0])));
                        $values = array_map(fn ($row) => '('.implode(', ', array_map(
                            fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value),
                            array_values((array) $row),
                        )).')', $chunk);
                        fwrite($handle, "INSERT INTO {$quoted} ({$columns}) VALUES\n".implode(",\n", $values).";\n");
                    }
                    $offset += self::ROWS_PER_READ;
                } while (count($rows) === self::ROWS_PER_READ);

                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        } finally {
            fclose($handle);
        }

        return $tables->count();
    }
}
