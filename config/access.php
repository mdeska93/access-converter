<?php

return [
    'path' => env('ACCESS_DATABASE_PATH', ''),
    'allowlist' => array_values(array_filter(array_map('trim', explode(',', env('ACCESS_TABLE_ALLOWLIST', ''))))),
    'chunk_size' => (int) env('EXPORT_CHUNK_SIZE', 10000),
    'max_rows' => (int) env('EXPORT_MAX_ROWS', 0),
    'csv_max_rows_per_file' => (int) env('EXPORT_CSV_MAX_ROWS_PER_FILE', 1000000),
    'max_file_size_mb' => (float) env('EXPORT_MAX_FILE_SIZE_MB', 10),
];
