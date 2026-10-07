# 🚀 Access Converter & Data Exporter (Laravel)

[![PHP Version](https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777bb4?logo=php)](https://php.net)
[![Laravel Framework](https://img.shields.io/badge/Laravel-11.x-ff2d20?logo=laravel)](https://laravel.com)
[![Platform](https://img.shields.io/badge/Platform-Windows%20(ODBC)-0078d4?logo=windows)](https://www.microsoft.com)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Aplikasi berbasis web lokal modern untuk membaca file database **Microsoft Access** (`.accdb` / `.mdb`), melihat pratinjau tabel secara interaktif, dan mengekspor data dalam skala besar ke format **Excel (.xlsx)**, **CSV (dengan fitur Auto-split)**, dan **SQL (.sql / chunked .zip)** dengan live progress bar.

---

## 🌟 Fitur Utama

- 🔍 **Direct Access Engine (No Import Needed)**: Membaca file database `.mdb` dan `.accdb` langsung melalui driver **PDO ODBC**, aman (*read-only*) tanpa mengubah atau merusak struktur database sumber.
- 📁 **Pemilihan & Pengalihan Database Dinamis**:
  - **Path Lokal Komputer**: Ketik atau tempel path lengkap file `.accdb` / `.mdb` di drive lokal tanpa batasan memori upload.
  - **Folder Scanner Assistant**: Pindai folder komputer untuk mendeteksi seluruh file Access secara otomatis dan memilihnya dengan satu klik.
  - **Riwayat Database (Recent Files)**: Menyimpan daftar database yang pernah dibuka untuk beralih instan antar database.
  - **Upload File Web**: Unggah file database baru langsung dari antarmuka web ke penyimpanan aplikasi.
- 📋 **Table Explorer & Preview**: Menjelajahi daftar tabel dan melihat pratinjau data (50 baris pertama) secara instan melalui antarmuka web.
- 📦 **Pilihan Format Ekspor Beragam**:
  - **CSV (.csv / .zip)**: Mendukung hingga jutaan baris data dengan pemecahan otomatis (*auto-split*) jika data melampaui batas baris per file (misal 1.000.000 baris/file).
  - **Excel (.xlsx)**: Menghasilkan spreadsheet rapi dengan PhpSpreadsheet (disarankan untuk dataset < 50.000 baris).
  - **SQL (.sql)**: Menghasilkan satu file query SQL (`INSERT INTO`) siap import untuk MySQL / MariaDB.
  - **SQL Chunks (.zip)**: Memecah export data besar menjadi banyak file SQL terpisah berdasarkan ukuran *chunk* (misalnya 10.000 record/file) yang dikompresi dalam satu file ZIP untuk mencegah *memory limit* atau *timeout* saat restore.
- ⏱️ **Real-time Progress Tracker**: Menampilkan visualisasi persentase, jumlah baris yang diproses, estimasi waktu tersisa, dan langsung men-download file setelah selesai.
- 🎯 **Filter Fleksibel (Offset & Limit)**: Menentukan titik mulai record (*offset*) dan batas total record (*limit*).
- 🛡️ **Table Whitelist / Security**: Mendukung pembatasan akses tabel melalui konfigurasi `ACCESS_TABLE_ALLOWLIST`.
- ⚡ **Otomatisasi Instalasi Windows**: Tersedia script `install-windows.bat` untuk pengaturan satu klik.

---

## 📋 Kebutuhan Sistem

1. **Sistem Operasi**: Windows (disarankan Windows 10/11 atau Windows Server).
2. **PHP**: Versi 8.2, 8.3, atau 8.4 dengan ekstensi:
   - `pdo`
   - `pdo_odbc` atau `odbc`
   - `zip`
   - `gd` atau `fileinfo` (untuk PhpSpreadsheet)
3. **Composer**: Versi 2.x
4. **Microsoft Access Database Engine (ODBC Driver)**:
   > ⚠️ **PENTING: Bitness Driver & PHP Wajib Sama!**  
   > Jika PHP yang digunakan adalah **64-bit (x64)**, Anda wajib menginstal **Microsoft Access Database Engine 64-bit**. Jika menggunakan PHP 32-bit (x86), pasang versi 32-bit.  
   > Unduh: [Microsoft Access Database Engine Redistributable](https://www.microsoft.com/en-us/download/details.aspx?id=54920)

---

## 🚀 Panduan Instalasi

### Cara Cepat (Windows)
1. Jalankan file `install-windows.bat` dengan klik ganda atau lewat Command Prompt:
   ```cmd
   install-windows.bat
   ```
2. Sesuaikan konfigurasi `.env` (lihat bagian [Konfigurasi](#-konfigurasi-env)).
3. Jalankan server:
   ```bash
   php artisan serve
   ```

---

### Cara Manual

1. **Clone repository ini**:
   ```bash
   git clone https://github.com/mdeska93/access-converter.git
   cd access-converter
   ```

2. **Install dependency**:
   ```bash
   composer install
   ```

3. **Salin file konfigurasi lingkungan**:
   ```bash
   cp .env.example .env
   ```

4. **Generate application key**:
   ```bash
   php artisan key:generate
   ```

5. **Buka file `.env` dan atur path database Access**:
   ```env
   ACCESS_DATABASE_PATH="C:\\path\\ke\\database_anda.accdb"
   ```

6. **Jalankan local development server**:
   ```bash
   php artisan serve
   ```
   Buka peramban di [http://127.0.0.1:8000](http://127.0.0.1:8000).

---

## ⚙️ Konfigurasi `.env`

Berikut variabel penting yang dapat disesuaikan pada file `.env`:

| Variabel | Deskripsi | Nilai Default |
|---|---|---|
| `ACCESS_DATABASE_PATH` | Path absolut ke file `.accdb` atau `.mdb` | `C:\Data\database.accdb` |
| `ACCESS_TABLE_ALLOWLIST` | Daftar tabel yang diizinkan (dipisahkan koma). Kosongkan untuk menampilkan semua tabel. | *(kosong)* |
| `EXPORT_CHUNK_SIZE` | Jumlah record default per chunk saat memproses export | `1000` |
| `EXPORT_MAX_ROWS` | Batas maksimum record yang boleh diexport (`0` = tanpa batas) | `0` |
| `EXPORT_MAX_FILE_SIZE_MB` | Ukuran maksimum (dalam MB) per file batch export (CSV / SQL Chunk) sebelum otomatis dipecah | `10` |
| `EXPORT_CSV_MAX_ROWS_PER_FILE` | Jumlah baris maksimum per file CSV sebelum dipecah menjadi file berikutnya | `1000000` |

---

## 📖 Cara Penggunaan

1. **Memilih Database**:
   - Klik **Ganti / Pilih File Database** untuk memilih database melalui Path Lokal di komputer, riwayat database sebelumnya, atau upload file.
2. **Memilih Tabel & Format**:
   - Di halaman utama, seluruh tabel yang terdeteksi pada file Access akan ditampilkan.
   - Klik **Lihat Data** pada kartu tabel untuk melihat 50 baris pertama data sampel beserta nama-nama kolomnya.
3. **Melakukan Ekspor Data**:
   - **Tabel**: Pilih tabel yang ingin diekspor.
   - **Format**:
     - `CSV (.csv / .zip)`: Untuk dataset besar, otomatis dipecah per 10 MB (atau batas ukuran yang ditentukan) dan maksimal 1.000.000 baris/file.
     - `Excel (.xlsx)`: Format Excel standar (disarankan untuk data di bawah 50.000 baris).
     - `SQL (.sql)`: Menghasilkan single file SQL dengan sintaks `INSERT INTO`.
     - `SQL ZIP (.zip)`: Menghasilkan potongan file SQL per 10 MB yang dikemas rapi dalam file ZIP.
   - **Maksimal Ukuran per File (MB)**: Mengatur batas ukuran maksimal per file (default: `10` MB). File akan otomatis di-split menjadi Part 01, Part 02, dst.
   - **Start / Offset**: Mulai dari baris ke berapa (misal `0` untuk mulai dari baris pertama).
   - **Limit Data**: Jumlah data yang ingin diambil (`0` berarti ekspor seluruh baris).
   - **Record Buffer (Chunk)**: Ukuran pembacaan memori per putaran query (contoh: `10000`).
3. **Mulai Export**:
   - Klik tombol **Mulai Export Data**.
   - Progress bar akan berjalan secara langsung menampilkan kemajuan ekspor.
   - Setelah selesai, unduhan file akan dimulai secara otomatis atau dapat diklik melalui tombol download yang muncul.

---

## 🛠️ Troubleshooting

### 1. Error: `[IM002] [Microsoft][ODBC Driver Manager] Data source name not found and no default driver specified`
- **Penyebab**: Driver Microsoft Access ODBC belum terpasang atau terdapat perbedaan arsitektur (bitness) antara PHP dan driver.
- **Solusi**:
  1. Cek bitness PHP Anda dengan menjalankan perintah:
     ```bash
     php -r "echo PHP_INT_SIZE === 8 ? '64-bit' : '32-bit';"
     ```
  2. Unduh dan pasang **Microsoft Access Database Engine 2016/2010 Redistributable** dengan bitness yang sama.

### 2. Error: `Could not find driver`
- Pastikan ekstensi `pdo_odbc` telah aktif di `php.ini`. Buka file `php.ini` Anda dan pastikan baris berikut tidak memiliki tanda titik koma (`;`) di depannya:
  ```ini
  extension=pdo_odbc
  extension=odbc
  ```

### 3. File Access Terkunci (*Locked*)
- Pastikan aplikasi Microsoft Access sedang tidak membuka database secara eksklusif sebelum melakukan ekspor.

---

## 📂 Struktur Proyek

```text
├── app/
│   ├── Http/Controllers/
│   │   └── AccessController.php   # Logika preview, chunking, SSE progress, & export
│   └── Services/
│       └── AccessService.php      # Koneksi PDO ODBC Access & query schema/data
├── config/
│   └── access.php                 # Konfigurasi custom Access Exporter
├── resources/views/
│   ├── layout.blade.php           # Template dasar HTML & Styling responsif
│   ├── home.blade.php             # Dashboard utama, list tabel, & form ekspor
│   └── table.blade.php            # Halaman pratinjau data tabel
├── routes/
│   └── web.php                    # Routing endpoint
├── install-windows.bat            # Script otomatisasi instalasi di Windows
└── storage/app/exports/           # Direktori penyimpanan sementara file export
```

---

## 📄 Lisensi

Proyek ini dirilis di bawah lisensi [MIT License](LICENSE). Silakan gunakan, pelajari, dan kembangkan sesuai kebutuhan.
