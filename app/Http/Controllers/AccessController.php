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
        try {
            $tables = $this->access->tables();
            $error = null;
        } catch (\Throwable $e) {
            $tables = [];
            $error = $e->getMessage();
        }
        return view('home', compact('tables', 'error'));
    }

    public function table(string $table)
    {
        try {
            $data = $this->access->rows($table, 0, 50);
            $error = null;
        } catch (\Throwable $e) {
            abort(400, $e->getMessage());
        }
        return view('table', compact('table', 'data', 'error'));
    }

    public function export(Request $r)
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $r->validate([
            'table' => 'required|string',
            'format' => 'required|in:csv,xlsx,sql,zip',
            'offset' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:0',
            'chunk' => 'nullable|integer|min:1|max:50000',
        ]);

        $table = $r->string('table')->toString();
        $format = $r->string('format')->toString();
        $offset = (int) $r->input('offset', 0);
        $limit = (int) $r->input('limit', 0);
        $chunk = (int) $r->input('chunk', config('access.chunk_size', 10000));

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
            return $this->csv($table, $offset, $limit, $chunk, $base, $dir, $cols, $maxRowsPerSheet);
        }

        if ($format === 'xlsx') {
            return $this->xlsx($table, $offset, $limit, $chunk, $base, $dir, $cols);
        }

        if ($format === 'sql') {
            return $this->sqlSingle($table, $offset, $limit, $chunk, $base, $dir, $cols);
        }

        return $this->sqlZip($table, $offset, $limit, $chunk, $base, $dir, $cols);
    }

    public function exportProgress(Request $r)
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $table = $r->string('table')->toString();
        $format = $r->string('format', 'csv')->toString();
        $offset = (int) $r->input('offset', 0);
        $limit = (int) $r->input('limit', 0);
        $chunk = (int) $r->input('chunk', config('access.chunk_size', 10000));
        if ($chunk < 1) $chunk = 10000;
        $maxRowsPerSheet = (int) $r->input('max_rows_per_sheet', config('access.csv_max_rows_per_file', 1000000));
        if ($maxRowsPerSheet < 1) $maxRowsPerSheet = 1000000;

        return response()->stream(function () use ($table, $format, $offset, $limit, $chunk, $maxRowsPerSheet) {
            $send = function ($data) {
                echo "data: " . json_encode($data) . "\n\n";
                if (ob_get_level() > 0) @ob_flush();
                @flush();
            };

            try {
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
                    'message' => "Mulai membaca data tabel '{$table}' (Target: " . number_format($totalRows, 0, ',', '.') . " baris, Chunk: " . number_format($chunk, 0, ',', '.') . ")...",
                ]);

                if ($format === 'csv') {
                    $this->streamCsv($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $maxRowsPerSheet, $send);
                } elseif ($format === 'xlsx') {
                    $this->streamXlsx($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $send);
                } elseif ($format === 'sql') {
                    $this->streamSqlSingle($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $send);
                } else {
                    $this->streamSqlZip($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $send);
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

    private function streamCsv($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, $maxRowsPerSheet, callable $send)
    {
        $cleanTable = preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
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

        // Buka file part/sheet pertama
        $openNextFile();

        $batchNo = 0;
        $processed = 0;

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use (&$fh, $cols, &$batchNo, &$processed, $totalRows, $send, &$currentFileRows, $maxRowsPerSheet, $openNextFile, &$partIndex, &$csvFiles) {
            if (connection_aborted()) return;

            $batchNo++;
            $count = count($rows);
            foreach ($rows as $row) {
                if ($currentFileRows >= $maxRowsPerSheet) {
                    $prevPart = $partIndex - 1;
                    $openNextFile();
                    $curPart = $partIndex - 1;
                    $send([
                        'event' => 'progress',
                        'batch' => $batchNo,
                        'batch_rows' => 0,
                        'processed' => $processed,
                        'total' => $totalRows,
                        'percent' => $totalRows > 0 ? min(99, round(($processed / $totalRows) * 100, 1)) : 100,
                        'message' => "Part #{$prevPart} (Sheet #{$prevPart}) mencapai " . number_format($maxRowsPerSheet, 0, ',', '.') . " baris. Membuka Part #{$curPart} (Sheet #{$curPart})...",
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
            $partInfo = count($csvFiles) > 1 ? " [Sheet/Part #{$currentPartNo}]" : "";

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

        // Jika hanya 1 file (total baris <= maxRowsPerSheet)
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
            // Lebih dari 1 part/sheet: kompres ke ZIP
            $totalParts = count($csvFiles);
            $send([
                'event' => 'progress',
                'batch' => $batchNo,
                'batch_rows' => 0,
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => 99,
                'message' => "Mengompres {$totalParts} file CSV (masing-masing maks " . number_format($maxRowsPerSheet, 0, ',', '.') . " baris) ke dalam arsip ZIP...",
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
                'message' => "Export CSV selesai! Total " . number_format($processed, 0, ',', '.') . " baris dibagi menjadi {$totalParts} file sheet/part (maks " . number_format($maxRowsPerSheet, 0, ',', '.') . " baris/file) dalam ZIP (" . $fileSize . ").",
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
            'message' => "Menyimpan dan mengompres file Excel (.xlsx)...",
        ]);

        $fileName = preg_replace('/[^A-Za-z0-9_-]/', '_', $table) . '.xlsx';
        $path = $base . '/' . $fileName;
        (new Xlsx($ss))->save($path);

        $fileSize = is_file($path) ? round(filesize($path) / 1024, 2) . ' KB' : '0 KB';
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

    private function streamSqlZip($table, $offset, $limit, $chunk, $base, $dir, $cols, $totalRows, callable $send)
    {
        $sqlFiles = [];
        $batchNo = 0;
        $processed = 0;

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use (&$batchNo, &$processed, &$sqlFiles, $base, $table, $cols, $totalRows, $send) {
            if (connection_aborted()) return;

            $batchNo++;
            $count = count($rows);
            $partName = sprintf('%s_%04d.sql', preg_replace('/[^A-Za-z0-9_-]/', '_', $table), $batchNo);
            $partPath = $base . '/' . $partName;
            $fh = fopen($partPath, 'wb');
            fwrite($fh, "-- Exported by Access Data Exporter (Part $batchNo)\n\n");
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $this->sqlValue($v), array_values($row));
                fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (`' . implode('`, `', array_map(fn($c) => str_replace('`', '``', $c), $cols)) . "`) VALUES (" . implode(', ', $vals) . ");\n");
            }
            fclose($fh);
            $sqlFiles[] = $partPath;
            $processed += $count;
            $percent = $totalRows > 0 ? min(90, round(($processed / $totalRows) * 90, 1)) : 90;

            $send([
                'event' => 'progress',
                'batch' => $batchNo,
                'batch_rows' => $count,
                'processed' => $processed,
                'total' => $totalRows,
                'percent' => $percent,
                'message' => "Chunk #{$batchNo} dibuat (" . number_format($count, 0, ',', '.') . " baris)... Total: " . number_format($processed, 0, ',', '.'),
            ]);
        });

        $send([
            'event' => 'progress',
            'batch' => $batchNo,
            'batch_rows' => 0,
            'processed' => $processed,
            'total' => $totalRows,
            'percent' => 95,
            'message' => "Mengompres {$batchNo} file SQL ke dalam arsip ZIP...",
        ]);

        $zipName = preg_replace('/[^A-Za-z0-9_-]/', '_', $table) . '.zip';
        $zipPath = $base . '/' . $zipName;
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($sqlFiles as $p) {
            $zip->addFile($p, basename($p));
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
            'message' => "Export SQL ZIP selesai! {$batchNo} batch berhasil dikompres (" . $fileSize . ").",
        ]);
    }

    private function csv($table, $offset, $limit, $chunk, $base, $dir, $cols, $maxRowsPerSheet)
    {
        $cleanTable = preg_replace('/[^A-Za-z0-9_-]/', '_', $table);
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

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use (&$fh, $cols, &$currentFileRows, $maxRowsPerSheet, $openNextFile) {
            foreach ($rows as $row) {
                if ($currentFileRows >= $maxRowsPerSheet) {
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

    private function sqlZip($table, $offset, $limit, $chunk, $base, $dir, $cols)
    {
        $sqlFiles = [];
        $idx = 0;

        $this->access->iterate($table, $offset, $limit, $chunk, function ($rows) use (&$idx, &$sqlFiles, $base, $table, $cols) {
            $idx++;
            $name = sprintf('%s_%04d.sql', preg_replace('/[^A-Za-z0-9_-]/', '_', $table), $idx);
            $path = $base . '/' . $name;
            $fh = fopen($path, 'wb');
            fwrite($fh, "-- Exported by Access Data Exporter (Part $idx)\n\n");
            foreach ($rows as $row) {
                $vals = array_map(fn($v) => $this->sqlValue($v), array_values($row));
                fwrite($fh, 'INSERT INTO `' . str_replace('`', '``', $table) . '` (`' . implode('`, `', array_map(fn($c) => str_replace('`', '``', $c), $cols)) . "`) VALUES (" . implode(', ', $vals) . ");\n");
            }
            fclose($fh);
            $sqlFiles[] = $path;
        });

        if (empty($sqlFiles)) {
            return back()->withErrors(['export' => 'Tidak ada data untuk diexport.']);
        }

        $zipPath = $base . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($sqlFiles as $p) {
            $zip->addFile($p, basename($p));
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
}
