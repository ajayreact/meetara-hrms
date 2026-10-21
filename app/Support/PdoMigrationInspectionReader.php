<?php

namespace App\Support;

use PDO;

/**
 * PDO adapter for a read-only inspection. Constructing it does not open a connection.
 */
final class PdoMigrationInspectionReader implements MigrationInspectionReader
{
    public function __construct(private PDO $pdo) {}

    public function select(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }
}
