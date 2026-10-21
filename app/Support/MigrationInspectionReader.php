<?php

namespace App\Support;

interface MigrationInspectionReader
{
    /**
     * @param  list<mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array;
}
