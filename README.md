# Access Data Exporter - Laravel

Aplikasi lokal untuk membaca Microsoft Access (`.mdb/.accdb`), preview tabel, export Excel, dan memecah data menjadi file SQL.

## Kebutuhan
- Windows direkomendasikan (Microsoft Access Database Engine/ODBC)
- PHP 8.2+ 64-bit (atau 32-bit sesuai driver ODBC)
- Composer
- Laravel 11

## Instalasi
1. Extract project.
2. Buka terminal di folder project.
3. Jalankan `composer install`.
4. Copy `.env.example` menjadi `.env`.
5. Jalankan `php artisan key:generate`.
6. Edit `.env`:
   `ACCESS_DATABASE_PATH=C:\Data\database.accdb`
7. Pastikan ODBC Microsoft Access Database Engine terpasang dan bitness PHP sama dengan driver ODBC.
8. Jalankan `php artisan serve`.
9. Buka `http://127.0.0.1:8000`.

## Export
- Excel: satu file `.xlsx`.
- SQL: membuat file SQL berdasarkan ukuran chunk.
- SQL ZIP: semua file SQL dikemas menjadi ZIP.
- `offset` = mulai dari record ke-N.
- `limit` = jumlah record; 0 berarti semua.
- `chunk` = jumlah record per file SQL.

Contoh 25.000 record dengan chunk 1.000 akan menghasilkan 25 file SQL.

## Catatan
Aplikasi menggunakan PDO ODBC secara langsung sehingga tidak mengubah database Access. SQL output ditujukan untuk database seperti MySQL/MariaDB; sesuaikan tipe/quoting bila target database berbeda.
