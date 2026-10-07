<?php

namespace App\Http\Controllers;

use App\Services\AccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

class AccessController extends Controller
{
    public function __construct(private AccessService $access) {}

    public function index()
    {
        $dbInfo = $this->access->getDatabaseInfo();
        $recentDatabases = $this->access->getRecentDatabases();
        $uploadMax = ini_get('upload_max_filesize') ?: '2M';
        $postMax = ini_get('post_max_size') ?: '8M';

        try {
            $tables = $this->access->tables();
            $error = null;
        } catch (\Throwable $e) {
            $tables = [];
            $error = $e->getMessage();
        }

        return view('home', compact('tables', 'error', 'dbInfo', 'recentDatabases', 'uploadMax', 'postMax'));
    }

    public function table(string $table)
    {
        $dbInfo = $this->access->getDatabaseInfo();
        try {
            $data = $this->access->rows($table, 0, 50);
            $error = null;
        } catch (\Throwable $e) {
            abort(400, $e->getMessage());
        }
        return view('table', compact('table', 'data', 'error', 'dbInfo'));
    }

    public function export(Request $r)
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        if ($r->filled('db_path')) {
            $this->access->setDatabasePath($r->input('db_path'));
        }

        $r->validate([
            'table' => 'required|string',
            'format' => 'required|in:csv,xlsx,sql,zip',
            'offset' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:0',
            'chunk' => 'nullable|integer|min:1|max:50000',
            'max_file_size_mb' => 'nullable|numeric|min:0.1',
        ]);

        $table = $r->string('table')->toString();
        $format = $r->string('format')->toString();
        $offset = (int) $r->input('offset', 0);
        $limit = (int) $r->input('limit', 0);
        $chunk = (int) $r->input('chunk', config('access.chunk_size', 10000));
        $maxFileSizeMb = (float) $r->input('max_file_size_mb', config('access.max_file_size_mb', 10));
        if ($maxFileSizeMb <= 0) $maxFileSizeMb = 10;

        $this->access->validateTable($table);

        if ($format === 'xlsx' && ($limit === 0 || $limit > 50000)) {
            return back()->withErrors([
                'export' => "Tabel '{$table}' memiliki jutaan data. Format Excel (.xlsx) dibatasi maksimal 50.000 baris per file untuk menghindari kehabisan memori. Gunakan format CSV (.csv) atau SQL ZIP untuk mengekspor seluruh data tanpa batas."
            ])->withInput();
        }

        $dir = 'exports/' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
        Storage::makeDirectory($dir);
        $base = Storage::path($dir);
        $cols = $this->access->columns($table);
        $maxRowsPerSheet = (int) $r->input('max_rows_per_sheet', config('access.csv_max_rows_per_file', 1000000));
        if ($maxRowsPerSheet < 1) $maxRowsPerSheet = 1000000;

        if ($format === 'csv') {
            return $this->csv($table, $offset, $limit, $chunk, $base, $dir, $cols, $maxRowsPerSheet, $maxFileSizeMb);
        }

        if ($format === 'xlsx') {
            return $this->xlsx($table, $offset, $limit, $chunk, $base, $dir, $cols);
        }

        if ($format === 'sql') {
            return $this->sqlSingle($table, $offset, $limit, $chunk, $base, $dir, $cols);
        }

        return $this->sqlZip($table, $offset, $limit, $chunk, $base, $dir, $cols, $maxFileSizeMb);
    }

    public function exportProgress(Request $r)
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $dbPath = $r->input('db_path');
        if (!empty($dbPath)) {
            $this->access->setDatabasePath($dbPath);
        }

        $table = $r->string('table')->toString();
        $format = $r->string('format', 'csv')->toString();
        $offset = (int) $r->input('offset', 0);
        $limit = (int) $r->input('limit', 0);
        $chunk = (int) $r->input('chunk', config('access.chunk_size', 10000));
        if ($chunk < 1) $chunk = 10000;
        $maxRowsPerSheet = (int) $r->input('max_rows_per_sheet', config('access.csv_max_rows_per_file', 1000000));
        if ($maxRowsPerSheet < 1) $maxRowsPerSheet = 1000000;
        $maxFileSizeMb = (float) $r->input('max_file_size_mb', config('access.max_file_size_mb', 10));
        if ($maxFileSizeMb <= 0) $maxFileSizeMb = 10;

        return response()->stream(function () use ($table, $format, $offset, $limit, $chunk, $maxRowsPerSheet, $maxFileSizeMb, $dbPath) {
            $send = function ($data) {
                echo "data: " . json_encode($data) . "\n\n";
                if (ob_get_level() > 0) @ob_flush();
                @flush();
            };

            try {
                if (!empty($dbPath)) {
                    $this->access->setDatabasePath($dbPath);
                }
                $this->access->validateTable($table);

                if ($format === 'xlsx' && ($limit === 0 || $limit > 50000)) {
                    $send([
                        'event' => 'error',
                        'message' => "Format Excel (.xlsx) dibatasi maksimal 50.000 baris per file untuk mencegah kehabisan memori RAM. Silakan gunakan format CSV (.csv) atau SQL ZIP untuk mengekspor data dalam jumlah besar tanpa batas."
                    ]);
                    return;
                }

                $send([
                    'event' => 'start',
                    'message' => "Menghubungkan ke database Access dan membaca metadata tabel '{$table}'...",
                ]);

                $totalRows = $limit > 0 ? $limit : ($this->access->count($table) ?? 0);
                $cols = $this->access->columns($table);

                $dir = 'exports/' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
                Storage::makeDirectory($dir);
                $base = Storage::path($dir);

                $send([
                    'event' => 'ready',
                    'table' => $table,
                    'total' => $totalRows,
                    'chunk' => $chunk,
                    'message' => "Mulai membaca data tabel '{$table}' (Target: " . number_format($totalRows, 0, ',', '.') . " baris, Batas Part: {$maxFileSizeMb} MB)...",
                ]);

                if ($format === 'csv') {
                    $this->streamCsv($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $maxRowsPerSheet, $maxFileSizeMb, $send);
                } elseif ($format === 'xlsx') {
                    $this->streamXlsx($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $send);
                } elseif ($format === 'sql') {
                    $this->streamSqlSingle($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $send);
                } else {
                    $this->streamSqlZip($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $maxFileSizeMb, $send);
                }
            } catch (\Throwable $e) {
                $send([
                    'event' => 'error',
                    'message' => $e->getMessage(),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    private function streamCsv($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $maxRowsPerSheet, $maxFileSizeMb, callable $send)
    {
        $cleanTable = preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
        $maxBytesPerFile = (int) ($maxFileSizeMb * 1024 * 1024);
        $csvFiles = [];
        $partIndex = 1;
        $currentFileRows = 0;
        $fh = null;

        $openNextFile = function () use (&$fh, &$csvFiles, &$partIndex, &$currentFileRows, $base, $cleanTable, $cols) {
            if ($fh) {
                fclose($fh);
            }
            $partName = sprintf('%s_part%02d.csv', $cleanTable, $partIndex);
            $partPath = $base . '/' . $partName;
            $fh = fopen($partPath, 'wb');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $cols);
            $csvFiles[] = $partPath;
            $currentFileRows = 0;
            $partIndex++;
        };

        // Buka file part pertama
        $openNextFile();

        $batchNo = 0;
        $processed = 0;

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use (&$fh, $cols, &$batchNo, &$processed, $totalRows, $send, &$currentFileRows, $maxRowsPerSheet, $maxBytesPerFile, $maxFileSizeMb, $openNextFile, &$partIndex, &$csvFiles) {
            if (connection_aborted()) return;

            $batchNo++;
            $count = count($rows);
            foreach ($rows as $row) {
                $curSize = ftell($fh);
                $reachedSize = ($maxBytesPerFile > 0 && $curSize >= $maxBytesPerFile);
                $reachedRows = ($maxRowsPerSheet > 0 && $currentFileRows >= $maxRowsPerSheet);

                if ($currentFileRows > 0 && ($reachedSize || $reachedRows)) {
                    $prevPart = $partIndex - 1;
                    $reason = $reachedSize
                        ? (round($curSize / 1024 / 1024, 2) . " MB (Batas {$maxFileSizeMb} MB)")
                        : (number_format($maxRowsPerSheet, 0, ',', '.') . " baris");
                    $openNextFile();
                    $curPart = $partIndex - 1;
                    $send([
                        'event' => 'progress',
                        'batch' => $batchNo,
                        'batch_rows' => 0,
                        'processed' => $processed,
                        'total' => $totalRows,
                        'percent' => $totalRows > 0 ? min(99, round(($processed / $totalRows) * 100, 1)) : 100,
                        'message' => "Part #{$prevPart} mencapai {$reason}. Melanjutkan ke Part #{$curPart}...",
                    ]);
                }

                $r = [];
                foreach ($cols as $c) {
                    $r[] = $row[$c] ?? '';
                }
                fputcsv($fh, $r);
                $currentFileRows++;
            }
            $processed += $count;
            $percent = $totalRows > 0 ? min(100, round(($processed / $totalRows) * 100, 1)) : 100;

            $currentPartNo = $partIndex - 1;
            $partInfo = count($csvFiles) > 1 ? " [Part #{$currentPartNo}]" : "";

            $send([
                'event' => 'progress',
                'batch' => $batchNo,
                'batch_rows' => $count,
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => $percent,
                'message' => "Batch #{$batchNo}{$partInfo}: " . number_format($processed, 0, ',', '.') . " baris telah ditulis (" . $percent . "%)",
            ]);
        });

        if ($fh) {
            fclose($fh);
        }

        // Jika hanya 1 file (total baris <= maxRowsPerSheet && size <= maxBytesPerFile)
        if (count($csvFiles) <= 1) {
            $singleName = $cleanTable . '.csv';
            $singlePath = $base . '/' . $singleName;
            if (!empty($csvFiles) && file_exists($csvFiles[0])) {
                rename($csvFiles[0], $singlePath);
            }
            $fileSize = is_file($singlePath) ? round(filesize($singlePath) / 1024 / 1024, 2) . ' MB' : '0 MB';
            $downloadUrl = route('download', ['file' => basename($dir) . '/' . $singleName]);

            $send([
                'event' => 'completed',
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => 100,
                'file_name' => $singleName,
                'file_size' => $fileSize,
                'download_url' => $downloadUrl,
                'message' => "Export CSV selesai! Total " . number_format($processed, 0, ',', '.') . " baris berhasil diexport (" . $fileSize . ").",
            ]);
        } else {
            // Lebih dari 1 part: kompres ke ZIP
            $totalParts = count($csvFiles);
            $send([
                'event' => 'progress',
                'batch' => $batchNo,
                'batch_rows' => 0,
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => 99,
                'message' => "Mengompres {$totalParts} file CSV (masing-masing maks {$maxFileSizeMb} MB) ke dalam arsip ZIP...",
            ]);

            $zipName = $cleanTable . '_csv.zip';
            $zipPath = $base . '/' . $zipName;
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($csvFiles as $p) {
                if (file_exists($p)) {
                    $zip->addFile($p, basename($p));
                }
            }
            $zip->close();

            foreach ($csvFiles as $p) {
                @unlink($p);
            }

            $fileSize = is_file($zipPath) ? round(filesize($zipPath) / 1024 / 1024, 2) . ' MB' : '0 MB';
            $downloadUrl = route('download', ['file' => basename($dir) . '/' . $zipName]);

            $send([
                'event' => 'completed',
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => 100,
                'file_name' => $zipName,
                'file_size' => $fileSize,
                'download_url' => $downloadUrl,
                'message' => "Export CSV selesai! Total " . number_format($processed, 0, ',', '.') . " baris dibagi menjadi {$totalParts} file part (maks {$maxFileSizeMb} MB per file) dalam ZIP (" . $fileSize . ").",
            ]);
        }
    }

    private function streamXlsx($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, callable $send)
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->fromArray([$cols], null, 'A1');
        $rowNo = 2;
        $batchNo = 0;
        $processed = 0;

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use ($ws, $cols, &$rowNo, &$batchNo, &$processed, $totalRows, $send) {
            if (connection_aborted()) return;

            $batchNo++;
            $count = count($rows);
            $data = [];
            foreach ($rows as $row) {
                $r = [];
                foreach ($cols as $c) {
                    $r[] = $row[$c] ?? null;
                }
                $data[] = $r;
            }
            if (!empty($data)) {
                $ws->fromArray($data, null, 'A' . $rowNo);
                $rowNo += $count;
            }
            $processed += $count;
            $percent = $totalRows > 0 ? min(95, round(($processed / $totalRows) * 95, 1)) : 95;

            $send([
                'event' => 'progress',
                'batch' => $batchNo,
                'batch_rows' => $count,
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => $percent,
                'message' => "Batch #{$batchNo} dimasukkan ke sheet: " . number_format($processed, 0, ',', '.') . " baris...",
            ]);
        });

        $send([
            'event' => 'progress',
            'batch' => $batchNo,
            'batch_rows' => 0,
            'processed' => $processed,
            'total' => $totalRows,
            'percent' => 98,
            'message' => "Menulis file Excel (.xlsx) ke storage...",
        ]);

        $fileName = preg_replace('/[^A-Za-z0-9_-]/', '_', $table) . '.xlsx';
        $path = $base . '/' . $fileName;
        (new Xlsx($ss))->save($path);

        $fileSize = is_file($path) ? round(filesize($path) / 1024 / 1024, 2) . ' MB' : '0 MB';
        $downloadUrl = route('download', ['file' => basename($dir) . '/' . $fileName]);

        $send([
            'event' => 'completed',
            'processed' => $processed,
            'total' => $totalRows,
            'percent' => 100,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'download_url' => $downloadUrl,
            'message' => "Export Excel selesai! Total " . number_format($processed, 0, ',', '.') . " baris berhasil diexport (" . $fileSize . ").",
        ]);
    }

    private function streamSqlSingle($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, callable $send)
    {
        $fileName = preg_replace('/[^A-Za-z0-9_-]/', '_', $table) . '.sql';
        $path = $base . '/' . $fileName;
        $fh = fopen($path, 'wb');
        fwrite($fh, "-- Exported by Access Data Exporter\n");
        fwrite($fh, "-- Table: " . $table . "\n\n");

        $batchNo = 0;
        $processed = 0;

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use ($fh, $table, $cols, &$batchNo, &$processed, $totalRows, $send) {
            if (connection_aborted()) return;

            $batchNo++;
            $count = count($rows);
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $this->sqlValue($v), array_values($row));
                fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (`' . implode('`, `', array_map(fn($c) => str_replace('`', '``', $c), $cols)) . "`) VALUES (" . implode(', ', $vals) . ");\n");
            }
            $processed += $count;
            $percent = $totalRows > 0 ? min(100, round(($processed / $totalRows) * 100, 1)) : 100;

            $send([
                'event' => 'progress',
                'batch' => $batchNo,
                'batch_rows' => $count,
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => $percent,
                'message' => "Batch #{$batchNo} SQL ditulis: " . number_format($processed, 0, ',', '.') . " baris (" . $percent . "%)",
            ]);
        });

        fclose($fh);

        $fileSize = is_file($path) ? round(filesize($path) / 1024 / 1024, 2) . ' MB' : '0 MB';
        $downloadUrl = route('download', ['file' => basename($dir) . '/' . $fileName]);

        $send([
            'event' => 'completed',
            'processed' => $processed,
            'total' => $totalRows,
            'percent' => 100,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'download_url' => $downloadUrl,
            'message' => "Export SQL selesai! Total " . number_format($processed, 0, ',', '.') . " baris berhasil diexport (" . $fileSize . ").",
        ]);
    }

    private function streamSqlZip($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $maxFileSizeMb, callable $send)
    {
        $cleanTable = preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
        $maxBytesPerFile = (int) ($maxFileSizeMb * 1024 * 1024);
        $sqlFiles = [];
        $partIndex = 1;
        $currentFileRows = 0;
        $fh = null;

        $openNextFile = function () use (&$fh, &$sqlFiles, &$partIndex, &$currentFileRows, $base, $cleanTable) {
            if ($fh) {
                fclose($fh);
            }
            $partName = sprintf('%s_part%02d.sql', $cleanTable, $partIndex);
            $partPath = $base . '/' . $partName;
            $fh = fopen($partPath, 'wb');
            fwrite($fh, "-- Exported by Access Data Exporter (Part {$partIndex})\n");
            fwrite($fh, "-- Table: {$cleanTable}\n\n");
            $sqlFiles[] = $partPath;
            $currentFileRows = 0;
            $partIndex++;
        };

        $openNextFile();

        $batchNo = 0;
        $processed = 0;

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use (&$fh, $cleanTable, $cols, &$batchNo, &$processed, $totalRows, $send, &$currentFileRows, $maxBytesPerFile, $maxFileSizeMb, $openNextFile, &$partIndex, &$sqlFiles) {
            if (connection_aborted()) return;

            $batchNo++;
            $count = count($rows);
            foreach ($rows as $row) {
                $curSize = ftell($fh);
                if ($currentFileRows > 0 && $maxBytesPerFile > 0 && $curSize >= $maxBytesPerFile) {
                    $prevPart = $partIndex - 1;
                    $openNextFile();
                    $curPart = $partIndex - 1;
                    $send([
                        'event' => 'progress',
                        'batch' => $batchNo,
                        'batch_rows' => 0,
                        'processed' => $processed,
                        'total' => $totalRows,
                        'percent' => $totalRows > 0 ? min(90, round(($processed / $totalRows) * 90, 1)) : 90,
                        'message' => "Part SQL #{$prevPart} mencapai batas " . round($curSize / 1024 / 1024, 2) . " MB. Melanjutkan ke Part SQL #{$curPart}...",
                    ]);
                }

                $vals = array_map(fn($v) => $this->sqlValue($v), array_values($row));
                fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $cleanTable) . '` (`' . implode('`, `', array_map(fn($c) => str_replace('`', '``', $c), $cols)) . "`) VALUES (" . implode(', ', $vals) . ");\n");
                $currentFileRows++;
            }
            $processed += $count;
            $percent = $totalRows > 0 ? min(90, round(($processed / $totalRows) * 90, 1)) : 90;

            $currentPartNo = $partIndex - 1;
            $partInfo = count($sqlFiles) > 1 ? " [Part SQL #{$currentPartNo}]" : "";

            $send([
                'event' => 'progress',
                'batch' => $batchNo,
                'batch_rows' => $count,
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => $percent,
                'message' => "Batch #{$batchNo}{$partInfo}: " . number_format($processed, 0, ',', '.') . " baris SQL ditulis (" . $percent . "%)",
            ]);
        });

        if ($fh) {
            fclose($fh);
        }

        $totalParts = count($sqlFiles);
        $send([
            'event' => 'progress',
            'batch' => $batchNo,
            'batch_rows' => 0,
            'processed' => $processed,
            'total' => $totalRows,
            'percent' => 95,
            'message' => "Mengompres {$totalParts} file SQL (masing-masing maks {$maxFileSizeMb} MB) ke dalam arsip ZIP...",
        ]);

        $zipName = $cleanTable . '_sql.zip';
        $zipPath = $base . '/' . $zipName;
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($sqlFiles as $p) {
            if (file_exists($p)) {
                $zip->addFile($p, basename($p));
            }
        }
        $zip->close();

        foreach ($sqlFiles as $p) {
            @unlink($p);
        }

        $fileSize = is_file($zipPath) ? round(filesize($zipPath) / 1024 / 1024, 2) . ' MB' : '0 MB';
        $downloadUrl = route('download', ['file' => basename($dir) . '/' . $zipName]);

        $send([
            'event' => 'completed',
            'processed' => $processed,
            'total' => $totalRows,
            'percent' => 100,
            'file_name' => $zipName,
            'file_size' => $fileSize,
            'download_url' => $downloadUrl,
            'message' => "Export SQL ZIP selesai! Data dibagi menjadi {$totalParts} file SQL (maks {$maxFileSizeMb} MB/file) dalam ZIP (" . $fileSize . ").",
        ]);
    }

    private function csv($table, $offset, $limit, $chunk, $base, $dir, $cols, $maxRowsPerSheet, $maxFileSizeMb = 10)
    {
        $cleanTable = preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
        $maxBytesPerFile = (int) ($maxFileSizeMb * 1024 * 1024);
        $csvFiles = [];
        $partIndex = 1;
        $currentFileRows = 0;
        $fh = null;

        $openNextFile = function () use (&$fh, &$csvFiles, &$partIndex, &$currentFileRows, $base, $cleanTable, $cols) {
            if ($fh) {
                fclose($fh);
            }
            $partName = sprintf('%s_part%02d.csv', $cleanTable, $partIndex);
            $partPath = $base . '/' . $partName;
            $fh = fopen($partPath, 'wb');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, $cols);
            $csvFiles[] = $partPath;
            $currentFileRows = 0;
            $partIndex++;
        };

        $openNextFile();

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use (&$fh, $cols, &$currentFileRows, $maxRowsPerSheet, $maxBytesPerFile, $openNextFile) {
            foreach ($rows as $row) {
                $curSize = ftell($fh);
                $reachedSize = ($maxBytesPerFile > 0 && $curSize >= $maxBytesPerFile);
                $reachedRows = ($maxRowsPerSheet > 0 && $currentFileRows >= $maxRowsPerSheet);

                if ($currentFileRows > 0 && ($reachedSize || $reachedRows)) {
                    $openNextFile();
                }
                $r = [];
                foreach ($cols as $c) {
                    $r[] = $row[$c] ?? '';
                }
                fputcsv($fh, $r);
                $currentFileRows++;
            }
        });

        if ($fh) {
            fclose($fh);
        }

        if (count($csvFiles) <= 1) {
            $singlePath = $base . '/' . $cleanTable . '.csv';
            if (!empty($csvFiles) && file_exists($csvFiles[0])) {
                rename($csvFiles[0], $singlePath);
                return response()->download($singlePath)->deleteFileAfterSend(true);
            }
            return back()->withErrors(['export' => 'Tidak ada data untuk diexport.']);
        } else {
            $zipName = $cleanTable . '_csv.zip';
            $zipPath = $base . '/' . $zipName;
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($csvFiles as $p) {
                if (file_exists($p)) {
                    $zip->addFile($p, basename($p));
                }
            }
            $zip->close();

            foreach ($csvFiles as $p) {
                @unlink($p);
            }

            return response()->download($zipPath)->deleteFileAfterSend(true);
        }
    }

    private function xlsx($table, $offset, $limit, $chunk, $base, $dir, $cols)
    {
        $ss = new Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->fromArray([$cols], null, 'A1');
        $rowNo = 2;

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use ($ws, $cols, &$rowNo) {
            $data = [];
            foreach ($rows as $row) {
                $r = [];
                foreach ($cols as $c) {
                    $r[] = $row[$c] ?? null;
                }
                $data[] = $r;
            }
            if (!empty($data)) {
                $ws->fromArray($data, null, 'A' . $rowNo);
                $rowNo += count($data);
            }
        });

        $path = $base . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $table) . '.xlsx';
        (new Xlsx($ss))->save($path);
        return response()->download($path)->deleteFileAfterSend(true);
    }

    private function sqlSingle($table, $offset, $limit, $chunk, $base, $dir, $cols)
    {
        $path = $base . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $table) . '.sql';
        $fh = fopen($path, 'wb');
        fwrite($fh, "-- Exported by Access Data Exporter\n");
        fwrite($fh, "-- Table: " . $table . "\n\n");

        $hasData = false;
        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use ($fh, $table, $cols, &$hasData) {
            $hasData = true;
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $this->sqlValue($v), array_values($row));
                fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (`' . implode('`, `', array_map(fn($c) => str_replace('`', '``', $c), $cols)) . "`) VALUES (" . implode(', ', $vals) . ");\n");
            }
        });

        fclose($fh);

        if (!$hasData) {
            @unlink($path);
            return back()->withErrors(['export' => 'Tidak ada data untuk diexport.']);
        }

        return response()->download($path)->deleteFileAfterSend(true);
    }

    private function sqlZip($table, $offset, $limit, $chunk, $base, $dir, $cols, $maxFileSizeMb = 10)
    {
        $cleanTable = preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
        $maxBytesPerFile = (int) ($maxFileSizeMb * 1024 * 1024);
        $sqlFiles = [];
        $partIndex = 1;
        $currentFileRows = 0;
        $fh = null;

        $openNextFile = function () use (&$fh, &$sqlFiles, &$partIndex, &$currentFileRows, $base, $cleanTable) {
            if ($fh) {
                fclose($fh);
            }
            $partName = sprintf('%s_part%02d.sql', $cleanTable, $partIndex);
            $partPath = $base . '/' . $partName;
            $fh = fopen($partPath, 'wb');
            fwrite($fh, "-- Exported by Access Data Exporter (Part {$partIndex})\n");
            fwrite($fh, "-- Table: {$cleanTable}\n\n");
            $sqlFiles[] = $partPath;
            $currentFileRows = 0;
            $partIndex++;
        };

        $openNextFile();

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use (&$fh, $cleanTable, $cols, &$currentFileRows, $maxBytesPerFile, $openNextFile) {
            foreach ($rows as $row) {
                $curSize = ftell($fh);
                if ($currentFileRows > 0 && $maxBytesPerFile > 0 && $curSize >= $maxBytesPerFile) {
                    $openNextFile();
                }
                $vals = array_map(fn($v) => $this->sqlValue($v), array_values($row));
                fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $cleanTable) . '` (`' . implode('`, `', array_map(fn($c) => str_replace('`', '``', $c), $cols)) . "`) VALUES (" . implode(', ', $vals) . ");\n");
                $currentFileRows++;
            }
        });

        if ($fh) {
            fclose($fh);
        }

        if (empty($sqlFiles) || ($currentFileRows === 0 && count($sqlFiles) === 1 && filesize($sqlFiles[0]) < 100)) {
            return back()->withErrors(['export' => 'Tidak ada data untuk diexport.']);
        }

        $zipPath = $base . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($sqlFiles as $p) {
            if (file_exists($p)) {
                $zip->addFile($p, basename($p));
            }
        }
        $zip->close();

        foreach ($sqlFiles as $p) {
            @unlink($p);
        }

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    private function sqlValue($v)
    {
        if ($v === null || $v === '') return 'NULL';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_numeric($v) && !preg_match('/^0\d+/', (string)$v)) return (string)$v;
        return "'" . str_replace("'", "''", (string)$v) . "'";
    }

    public function download(string $file)
    {
        $path = Storage::path('exports/' . $file);
        abort_unless(is_file($path), 404);
        return response()->download($path);
    }

    public function selectDatabase(Request $r)
    {
        $r->validate([
            'path' => 'required|string',
        ], [
            'path.required' => 'Path file database harus diisi.',
        ]);

        $path = $r->input('path');

        try {
            $this->access->setActivePath($path);
            $filename = basename($path);
            return back()->with('success', "Database berhasil dialihkan ke: '{$filename}'");
        } catch (\Throwable $e) {
            return back()->withErrors(['db_error' => $e->getMessage()])->withInput();
        }
    }

    public function uploadDatabase(Request $r)
    {
        if (empty($_FILES) && empty($_POST) && isset($_SERVER['REQUEST_METHOD']) && strtolower($_SERVER['REQUEST_METHOD']) === 'post') {
            $postMax = ini_get('post_max_size') ?: '8M';
            return back()->withErrors([
                'db_error' => "Ukuran file melebihi batas 'post_max_size' server ({$postMax}). Untuk file besar, gunakan opsi 'Path Lokal di Komputer' untuk langsung membuka file tanpa upload!"
            ]);
        }

        $r->validate([
            'access_file' => 'required|file',
        ], [
            'access_file.required' => 'Pilih file Access (.accdb / .mdb) yang ingin diupload.',
        ]);

        $file = $r->file('access_file');
        if (!$file->isValid()) {
            return back()->withErrors([
                'db_error' => "Gagal mengupload file: " . $file->getErrorMessage() . ". Untuk file besar, disarankan menggunakan tab 'Path Lokal'!"
            ]);
        }

        try {
            $savedPath = $this->access->storeUploadedFile($file);
            $filename = basename($savedPath);
            return back()->with('success', "File database '{$filename}' berhasil diupload dan aktif!");
        } catch (\Throwable $e) {
            return back()->withErrors(['db_error' => $e->getMessage()]);
        }
    }

    public function scanFolder(Request $r)
    {
        $folder = $r->input('folder', '');
        if (!$folder) {
            return response()->json([
                'success' => false,
                'message' => 'Path folder harus diisi.'
            ], 422);
        }

        try {
            $files = $this->access->scanFolder($folder);
            return response()->json([
                'success' => true,
                'folder' => $this->access->sanitizePath($folder),
                'files' => $files,
                'count' => count($files),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function resetDatabase()
    {
        $this->access->resetDatabase();
        return back()->with('success', 'Database telah dikembalikan ke default (.env)!');
    }

    public function removeRecentDatabase(Request $r)
    {
        $path = $r->input('path', '');
        if ($path) {
            $this->access->removeRecentDatabase($path);
        }
        return back()->with('success', 'File telah dihapus dari daftar riwayat.');
    }
}
