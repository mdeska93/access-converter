<?php
namespace App\Services;
use PDO;
use RuntimeException;
class AccessService
{
    public function connect(): PDO
    {
        $path = config('access.path');
        if (!$path) throw new RuntimeException('ACCESS_DATABASE_PATH belum diisi di file .env.');
        if (!is_file($path)) throw new RuntimeException("File Access tidak ditemukan: {$path}");
        $dsn = 'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $path . ';Uid=Admin;Pwd=;';
        try { return new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
        catch (\Throwable $e) { throw new RuntimeException('Gagal konek Microsoft Access. Pastikan Microsoft Access Database Engine/ODBC terpasang dan bitness PHP sama dengan ODBC (32/64-bit). Detail: '.$e->getMessage()); }
    }
    public function tables(): array
    {
        $path = config('access.path');
        if (!$path) throw new RuntimeException('ACCESS_DATABASE_PATH belum diisi di file .env.');
        if (!is_file($path)) throw new RuntimeException("File Access tidak ditemukan: {$path}");

        $dsn = 'Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $path . ';Uid=Admin;Pwd=;';
        $conn = @odbc_connect($dsn, '', '');
        $tables = [];

        if ($conn) {
            $result = @odbc_tables($conn);
            if ($result) {
                while ($row = @odbc_fetch_array($result)) {
                    $type = strtoupper(trim($row['TABLE_TYPE'] ?? ''));
                    $name = trim($row['TABLE_NAME'] ?? '');
                    if ($type !== 'SYSTEM TABLE' && !str_starts_with($name, 'MSys') && !str_starts_with($name, '~')) {
                        $tables[] = $name;
                    }
                }
            }
            @odbc_close($conn);
        }

        $allow = config('access.allowlist');
        if ($allow) $tables = array_values(array_filter($tables, fn($t) => in_array($t, $allow, true)));
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
        return $tables;
    }
    public function validateTable(string $table): string
    {
        if (!in_array($table, $this->tables(), true)) throw new RuntimeException('Tabel tidak diizinkan/tidak ditemukan.');
        return '[' . str_replace(']', ']]', $table) . ']';
    }
    public function count(string $table): ?int
    {
        try {
            $pdo = $this->connect();
            $safe = $this->validateTable($table);
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$safe}");
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
    }
    public function columns(string $table): array
    {
        $this->validateTable($table);
        $path = config('access.path');
        $dsn = 'Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $path . ';Uid=Admin;Pwd=;';
        $conn = @odbc_connect($dsn, '', '');
        $cols = [];

        if ($conn) {
            $res = @odbc_columns($conn, null, null, $table);
            if ($res) {
                while ($row = @odbc_fetch_array($res)) {
                    if (!empty($row['COLUMN_NAME'])) {
                        $cols[] = $row['COLUMN_NAME'];
                    }
                }
            }
            @odbc_close($conn);
        }

        if (empty($cols)) {
            $pdo = $this->connect();
            $safe = $this->validateTable($table);
            $stmt = $pdo->query("SELECT TOP 1 * FROM {$safe}");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $cols = array_keys($row);
            }
        }

        return $cols;
    }
    public function rows(string $table, int $offset=0, int $limit=100): array
    {
        $pdo=$this->connect(); $safe=$this->validateTable($table); $columns=$this->columns($table);
        $stmt=$pdo->query("SELECT * FROM {$safe}"); $out=[]; $i=0;
        while($i<$offset && $stmt->fetch(PDO::FETCH_ASSOC)!==false) $i++;
        while(count($out)<$limit && ($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false) $out[]=$row;
        return ['columns'=>$columns,'rows'=>$out,'offset'=>$offset,'limit'=>$limit];
    }
    public function iterate(string $table, int $offset, int $limit, int $chunk, callable $callback): int
    {
        $pdo=$this->connect(); $safe=$this->validateTable($table); $stmt=$pdo->query("SELECT * FROM {$safe}");
        $i=0; $taken=0; $batch=[];
        while(($row=$stmt->fetch(PDO::FETCH_ASSOC))!==false){
            if($i++<$offset) continue;
            if($limit>0 && $taken >= $limit) break;
            $batch[]=$row; $taken++;
            if(count($batch)>=$chunk){ $callback($batch, $taken-count($batch)); $batch=[]; }
        }
        if($batch) $callback($batch, $taken-count($batch)); return $taken;
    }
}
