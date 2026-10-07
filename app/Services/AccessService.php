<?php

namespace App\Services;

use PDO;
use RuntimeException;

class AccessService
{
    private ?string $overridePath = null;

    /**
     * Set temporary override for database path (useful for single request or stream)
     */
    public function setDatabasePath(?string $path): void
    {
        $this->overridePath = $path ? $this->sanitizePath($path) : null;
    }

    /**
     * Clean and normalize Windows file path
     */
    public function sanitizePath(?string $path): string
    {
        if ($path === null) {
            return '';
        }
        $path = trim($path);
        // Remove surrounding single or double quotes
        $path = trim($path, "\"' \t\n\r\0\x0B");
        // Convert mixed slashes to system directory separator
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        return trim($path);
    }

    /**
     * Get currently active database path
     */
    public function getActivePath(): string
    {
        // 1. Runtime override
        if (!empty($this->overridePath)) {
            return $this->overridePath;
        }

        // 2. Session database path
        if (function_exists('session') && session()->has('access_database_path')) {
            $sess = $this->sanitizePath(session('access_database_path'));
            if ($sess !== '') {
                return $sess;
            }
        }

        // 3. Fallback to .env / config
        $cfg = $this->sanitizePath(config('access.path'));
        if ($cfg !== '') {
            return $cfg;
        }

        return '';
    }

    /**
     * Switch active database path after testing connection
     */
    public function setActivePath(string $path): void
    {
        $clean = $this->sanitizePath($path);
        if ($clean === '') {
            throw new RuntimeException('Path file database tidak boleh kosong.');
        }

        if (!is_file($clean)) {
            throw new RuntimeException("File Access tidak ditemukan di path: '{$clean}'. Pastikan drive dan nama file sudah benar.");
        }

        $ext = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if (!in_array($ext, ['accdb', 'mdb'], true)) {
            throw new RuntimeException("File harus berekstensi Microsoft Access (.accdb atau .mdb). Ekstensi file yang dipilih: .{$ext}");
        }

        // Test connection before setting active
        $this->testConnection($clean);

        if (function_exists('session')) {
            session(['access_database_path' => $clean]);
        }

        $this->addRecentDatabase($clean);
    }

    /**
     * Reset active database to default from .env
     */
    public function resetDatabase(): void
    {
        if (function_exists('session')) {
            session()->forget('access_database_path');
        }
        $this->overridePath = null;
    }

    /**
     * Test connection to Access database
     */
    public function testConnection(?string $path = null): bool
    {
        $target = $path ? $this->sanitizePath($path) : $this->getActivePath();
        if (!$target) {
            throw new RuntimeException('Belum ada file database Access yang dipilih.');
        }
        if (!is_file($target)) {
            throw new RuntimeException("File Access tidak ditemukan: {$target}");
        }

        $dsn = 'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $target . ';Uid=Admin;Pwd=;';
        try {
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5
            ]);
            // Run lightweight query to check readability
            $conn = @odbc_connect('Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $target . ';Uid=Admin;Pwd=;', '', '');
            if ($conn) {
                @odbc_close($conn);
            }
            return true;
        } catch (\Throwable $e) {
            throw new RuntimeException('Gagal konek Microsoft Access. Pastikan Microsoft Access Database Engine/ODBC terpasang dan bitness PHP sama dengan ODBC (32/64-bit). Detail: ' . $e->getMessage());
        }
    }

    /**
     * Get detailed info of database
     */
    public function getDatabaseInfo(?string $path = null): array
    {
        $p = $path ? $this->sanitizePath($path) : $this->getActivePath();
        $exists = ($p !== '' && is_file($p));
        $defaultPath = $this->sanitizePath(config('access.path'));

        return [
            'path' => $p,
            'filename' => $p !== '' ? basename($p) : '(Belum dipilih)',
            'directory' => $p !== '' ? dirname($p) : '',
            'exists' => $exists,
            'size' => $exists ? filesize($p) : 0,
            'size_human' => $exists ? $this->humanFileSize(filesize($p)) : '0 B',
            'modified_at' => $exists ? date('d M Y H:i', filemtime($p)) : '-',
            'is_default' => ($p !== '' && strcasecmp($p, $defaultPath) === 0),
            'is_custom' => ($p !== '' && strcasecmp($p, $defaultPath) !== 0),
            'extension' => $p !== '' ? strtoupper(pathinfo($p, PATHINFO_EXTENSION)) : '',
        ];
    }

    /**
     * Path to recent databases JSON storage
     */
    private function getRecentStorageFile(): string
    {
        $dir = storage_path('app');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'recent_databases.json';
    }

    /**
     * Get list of recent databases
     */
    public function getRecentDatabases(): array
    {
        $file = $this->getRecentStorageFile();
        $recent = [];

        if (is_file($file)) {
            $json = @file_get_contents($file);
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $recent = $decoded;
            }
        }

        // Always include default path from config if it exists
        $defaultPath = $this->sanitizePath(config('access.path'));
        $hasDefault = false;
        foreach ($recent as $item) {
            if (strcasecmp($item['path'] ?? '', $defaultPath) === 0) {
                $hasDefault = true;
                break;
            }
        }

        if (!$hasDefault && $defaultPath !== '' && is_file($defaultPath)) {
            array_unshift($recent, [
                'path' => $defaultPath,
                'filename' => basename($defaultPath),
                'size_human' => $this->humanFileSize(filesize($defaultPath)),
                'last_used' => date('d M Y H:i'),
                'is_default' => true,
            ]);
        }

        $activePath = $this->getActivePath();
        $enriched = [];

        foreach ($recent as $item) {
            $p = $this->sanitizePath($item['path'] ?? '');
            if (!$p) continue;
            $exists = is_file($p);
            $enriched[] = [
                'path' => $p,
                'filename' => basename($p),
                'directory' => dirname($p),
                'size_human' => $exists ? $this->humanFileSize(filesize($p)) : ($item['size_human'] ?? '0 B'),
                'exists' => $exists,
                'last_used' => $item['last_used'] ?? '-',
                'is_active' => ($activePath !== '' && strcasecmp($p, $activePath) === 0),
                'is_default' => ($defaultPath !== '' && strcasecmp($p, $defaultPath) === 0),
                'extension' => strtoupper(pathinfo($p, PATHINFO_EXTENSION)),
            ];
        }

        return $enriched;
    }

    /**
     * Add database to recent list
     */
    public function addRecentDatabase(string $path): void
    {
        $clean = $this->sanitizePath($path);
        if (!$clean || !is_file($clean)) {
            return;
        }

        $file = $this->getRecentStorageFile();
        $recent = [];
        if (is_file($file)) {
            $decoded = json_decode(@file_get_contents($file), true);
            if (is_array($decoded)) {
                $recent = $decoded;
            }
        }

        // Remove duplicate
        $filtered = array_values(array_filter($recent, fn($r) => strcasecmp($r['path'] ?? '', $clean) !== 0));

        // Prepend new item
        array_unshift($filtered, [
            'path' => $clean,
            'filename' => basename($clean),
            'size_human' => $this->humanFileSize(filesize($clean)),
            'last_used' => date('d M Y H:i'),
            'is_default' => strcasecmp($clean, $this->sanitizePath(config('access.path'))) === 0,
        ]);

        // Keep at most 20 items
        $filtered = array_slice($filtered, 0, 20);

        @file_put_contents($file, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Remove database from recent list
     */
    public function removeRecentDatabase(string $path): void
    {
        $clean = $this->sanitizePath($path);
        $file = $this->getRecentStorageFile();
        if (!is_file($file)) {
            return;
        }

        $recent = json_decode(@file_get_contents($file), true);
        if (!is_array($recent)) {
            return;
        }

        $filtered = array_values(array_filter($recent, fn($r) => strcasecmp($r['path'] ?? '', $clean) !== 0));
        @file_put_contents($file, json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Scan folder for all .accdb and .mdb files
     */
    public function scanFolder(string $folder): array
    {
        $clean = $this->sanitizePath($folder);
        if (!is_dir($clean)) {
            throw new RuntimeException("Folder tidak ditemukan: '{$clean}'. Pastikan path folder sudah benar.");
        }

        $items = @scandir($clean);
        if ($items === false) {
            throw new RuntimeException("Tidak dapat mengakses folder: '{$clean}'. Periksa izin akses (permission).");
        }

        $results = [];
        $activePath = $this->getActivePath();

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '~$') || str_starts_with($item, '~')) {
                continue;
            }
            $fullPath = rtrim($clean, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $item;
            if (is_file($fullPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, ['accdb', 'mdb'], true)) {
                    $results[] = [
                        'name' => $item,
                        'path' => $fullPath,
                        'size' => filesize($fullPath),
                        'size_human' => $this->humanFileSize(filesize($fullPath)),
                        'modified_at' => date('d M Y H:i', filemtime($fullPath)),
                        'is_active' => ($activePath !== '' && strcasecmp($fullPath, $activePath) === 0),
                        'extension' => strtoupper($ext),
                    ];
                }
            }
        }

        usort($results, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $results;
    }

    /**
     * Save uploaded .accdb or .mdb file and activate it
     */
    public function storeUploadedFile($uploadedFile): string
    {
        $ext = strtolower($uploadedFile->getClientOriginalExtension());
        if (!in_array($ext, ['accdb', 'mdb'], true)) {
            throw new RuntimeException("Format file tidak didukung (.{$ext}). Harap upload file .accdb atau .mdb.");
        }

        $dir = storage_path('app/databases');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $origName = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
        $cleanOrigName = preg_replace('/[^A-Za-z0-9_-]/', '_', $origName);
        $fileName = $cleanOrigName . '_' . date('Ymd_His') . '.' . $ext;
        $targetPath = $dir . DIRECTORY_SEPARATOR . $fileName;

        $uploadedFile->move($dir, $fileName);

        if (!is_file($targetPath)) {
            throw new RuntimeException("Gagal memindahkan file upload ke direktori penyimpanan.");
        }

        $this->setActivePath($targetPath);
        return $targetPath;
    }

    /**
     * Format bytes to human readable size
     */
    public function humanFileSize(int $bytes, int $decimals = 2): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / pow(1024, $i), $decimals) . ' ' . $units[$i];
    }

    public function connect(): PDO
    {
        $path = $this->getActivePath();
        if (!$path) throw new RuntimeException('Belum ada database Access yang dipilih atau dikonfigurasi.');
        if (!is_file($path)) throw new RuntimeException("File Access tidak ditemukan: {$path}");
        $dsn = 'odbc:Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=' . $path . ';Uid=Admin;Pwd=;';
        try {
            return new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Gagal konek Microsoft Access. Pastikan Microsoft Access Database Engine/ODBC terpasang dan bitness PHP sama dengan ODBC (32/64-bit). Detail: ' . $e->getMessage());
        }
    }

    public function tables(): array
    {
        $path = $this->getActivePath();
        if (!$path) throw new RuntimeException('Belum ada database Access yang dipilih atau dikonfigurasi.');
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
        if (!empty($allow)) {
            $tables = array_values(array_filter($tables, fn($t) => in_array($t, $allow, true)));
        }
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
        return $tables;
    }

    public function validateTable(string $table): string
    {
        if (!in_array($table, $this->tables(), true)) {
            throw new RuntimeException('Tabel tidak diizinkan/tidak ditemukan.');
        }
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
        $path = $this->getActivePath();
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

    public function rows(string $table, int $offset = 0, int $limit = 100): array
    {
        $pdo = $this->connect();
        $safe = $this->validateTable($table);
        $columns = $this->columns($table);
        $stmt = $pdo->query("SELECT * FROM {$safe}");
        $out = [];
        $i = 0;
        while ($i < $offset && $stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            $i++;
        }
        while (count($out) < $limit && ($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $out[] = $row;
        }
        return ['columns' => $columns, 'rows' => $out, 'offset' => $offset, 'limit' => $limit];
    }

    public function iterate(string $table, int $offset, int $limit, int $chunk, callable $callback): int
    {
        $pdo = $this->connect();
        $safe = $this->validateTable($table);
        $stmt = $pdo->query("SELECT * FROM {$safe}");
        $i = 0;
        $taken = 0;
        $batch = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if ($i++ < $offset) continue;
            if ($limit > 0 && $taken >= $limit) break;
            $batch[] = $row;
            $taken++;
            if (count($batch) >= $chunk) {
                $callback($batch, $taken - count($batch));
                $batch = [];
            }
        }
        if ($batch) {
            $callback($batch, $taken - count($batch));
        }
        return $taken;
    }
}
