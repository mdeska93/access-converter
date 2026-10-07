@extends('layout')

@section('content')
<div class="card" style="border-left: 5px solid var(--primary);">
    <h1>Microsoft Access Data Exporter</h1>
    <p style="margin:0;">Jelajahi dan ekspor database Microsoft Access (<code>.accdb</code> / <code>.mdb</code>) ke format Excel, CSV (Auto-split), dan SQL dengan live progress tracker.</p>
</div>

{{-- NOTIFIKASI SUKSES / ERROR --}}
@if(session('success'))
    <div class="alert alert-success">
        <span style="font-size:18px;">✅</span>
        <div style="flex:1;">
            <b>Sukses!</b> {{ session('success') }}
        </div>
    </div>
@endif

@if($errors->has('db_error') || session('error'))
    <div class="alert alert-danger">
        <span style="font-size:18px;">⚠️</span>
        <div style="flex:1;">
            <b>Peringatan Database:</b> {{ $errors->first('db_error') ?: session('error') }}
        </div>
    </div>
@endif

@if($error)
    <div class="alert alert-danger">
        <span style="font-size:18px;">❌</span>
        <div style="flex:1;">
            <b>Koneksi ODBC Bermasalah:</b> {{ $error }}
        </div>
    </div>
@endif

@if($errors->has('export'))
    <div class="alert alert-danger">
        <span style="font-size:18px;">⚠️</span>
        <div style="flex:1;">
            <b>Gagal Export:</b> {{ $errors->first('export') }}
        </div>
    </div>
@endif

{{-- CARD PEMILIHAN FILE DATABASE ACCESS --}}
<div class="card" style="background:#ffffff;border:1px solid #cbd5e1;box-shadow:0 4px 12px rgba(0,0,0,0.04);">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
            <div style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">
                File Database Access Saat Ini:
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <span style="font-size:20px;font-weight:800;color:#0f172a;">
                    {{ $dbInfo['filename'] }}
                </span>
                @if(!empty($dbInfo['extension']))
                    <span class="badge badge-info">{{ $dbInfo['extension'] }}</span>
                @endif
                @if($dbInfo['exists'])
                    <span class="badge badge-success">🟢 Terhubung (ODBC OK)</span>
                    <span class="badge badge-neutral">Ukuran: {{ $dbInfo['size_human'] }}</span>
                @else
                    <span class="badge badge-danger">🔴 File Tidak Ditemukan</span>
                @endif
                @if($dbInfo['is_default'])
                    <span class="badge badge-neutral" title="Sesuai konfigurasi ACCESS_DATABASE_PATH di .env">Default .env</span>
                @elseif($dbInfo['is_custom'])
                    <span class="badge badge-warning" title="File dipilih secara dinamis">Kustom</span>
                    <form method="POST" action="{{ route('database.reset') }}" style="display:inline;margin:0;">
                        @csrf
                        <button type="submit" class="btn outline-gray sm" title="Kembalikan ke database default yang ada di file .env">
                            🔄 Reset ke Default (.env)
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <button id="toggleDbChooser" type="button" class="btn outline" style="font-size:13px;padding:8px 14px;">
            <span id="toggleDbIcon">📂</span>
            <span id="toggleDbText">Ganti / Pilih File Database</span>
        </button>
    </div>

    {{-- PATH BAR DENGAN TOMBOL SALIN --}}
    @if($dbInfo['path'])
        <div style="display:flex;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:6px 12px;gap:10px;margin-bottom:18px;">
            <span style="font-size:12px;font-weight:700;color:#64748b;white-space:nowrap;">Path:</span>
            <code id="currentDbPath" style="font-size:13px;color:#334155;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;">
                {{ $dbInfo['path'] }}
            </code>
            <button type="button" class="btn outline-gray sm" onclick="copyCurrentPath()" id="btnCopyPath" style="flex-shrink:0;">
                📋 Salin
            </button>
        </div>
    @endif

    {{-- PANEL PILIH DATABASE (TABS) --}}
    <div id="dbChooserPanel" style="{{ ($errors->has('db_error') || !$dbInfo['exists']) ? 'display:block;' : 'display:none;' }}border-top:1px solid #e2e8f0;padding-top:20px;">
        <h3 style="margin-bottom:14px;color:#0f172a;display:flex;align-items:center;gap:8px;">
            <span>Pilih Sumber File Access (.accdb / .mdb)</span>
        </h3>

        <div class="tabs-nav">
            <button class="tab-btn active" onclick="switchDbTab('tab-local', this)">
                💻 Path Lokal Komputer
            </button>
            <button class="tab-btn" onclick="switchDbTab('tab-recent', this)">
                🕒 Riwayat Database ({{ count($recentDatabases) }})
            </button>
            <button class="tab-btn" onclick="switchDbTab('tab-upload', this)">
                📤 Upload File
            </button>
        </div>

        {{-- TAB 1: PATH LOKAL KOMPUTER --}}
        <div id="tab-local" class="tab-pane active">
            <p style="font-size:13px;color:#475569;margin-bottom:12px;">
                Masukkan path lengkap file database Access di komputer lokal Anda (contoh: <code>C:\Work\Data.accdb</code> atau <code>D:\Project\database.mdb</code>).
                Metode ini <b>instan dan tidak terbatas ukuran file</b>.
            </p>

            <form method="POST" action="{{ route('database.select') }}" style="margin-bottom:20px;">
                @csrf
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:280px;">
                        <input type="text" name="path" id="inputLocalPath" value="{{ old('path', $dbInfo['path']) }}" placeholder="Contoh: C:\Work\DTSEN\DTSEN V3\export\DTSEN.accdb" required>
                    </div>
                    <button type="submit" class="btn" style="flex-shrink:0;">
                        🚀 Buka Database Ini
                    </button>
                </div>
            </form>

            {{-- FOLDER SCANNER ASSISTANT --}}
            <div style="background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:10px;padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
                    <div>
                        <b style="font-size:14px;color:#1e293b;">🔍 Bantuan Cari File: Scan Folder</b>
                        <div style="font-size:12px;color:#64748b;">Ketik path folder untuk menampilkan semua file .accdb dan .mdb di folder tersebut:</div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                    <div style="flex:1;min-width:280px;">
                        <input type="text" id="scanFolderInput" value="{{ $dbInfo['directory'] ?: 'C:\Work\DTSEN\DTSEN V3\export' }}" placeholder="Contoh folder: C:\Work\DTSEN\DTSEN V3\export">
                    </div>
                    <button type="button" id="btnScanFolder" class="btn outline" style="flex-shrink:0;" onclick="scanLocalFolder()">
                        🔍 Scan Folder
                    </button>
                </div>

                {{-- HASIL SCAN FOLDER --}}
                <div id="scanFolderLoading" style="display:none;font-size:13px;color:#2563eb;padding:10px 0;">
                    <span style="display:inline-block;width:14px;height:14px;border:2px solid #2563eb;border-top-color:transparent;border-radius:50%;animation:spin 0.8s linear infinite;vertical-align:middle;margin-right:6px;"></span>
                    Sedang memindai file Access di dalam folder...
                </div>
                <div id="scanFolderResults" style="display:none;margin-top:12px;"></div>
            </div>
        </div>

        {{-- TAB 2: RIWAYAT FILE TERAKHIR --}}
        <div id="tab-recent" class="tab-pane">
            <p style="font-size:13px;color:#475569;margin-bottom:14px;">
                Pilih dari database Access yang pernah dibuka sebelumnya:
            </p>

            @if(empty($recentDatabases))
                <div style="text-align:center;padding:24px;background:#f8fafc;border-radius:8px;color:#64748b;font-size:13px;">
                    Belum ada riwayat file yang tersimpan.
                </div>
            @else
                <div style="display:flex;flex-direction:column;gap:10px;">
                    @foreach($recentDatabases as $recent)
                        <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid {{ $recent['is_active'] ? '#3b82f6' : '#e2e8f0' }};border-radius:8px;padding:12px 16px;gap:12px;flex-wrap:wrap;box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                            <div style="flex:1;min-width:250px;">
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <b style="font-size:15px;color:#0f172a;">{{ $recent['filename'] }}</b>
                                    <span class="badge badge-info sm">{{ $recent['extension'] ?: 'ACCDB' }}</span>
                                    <span class="badge badge-neutral sm">{{ $recent['size_human'] }}</span>
                                    @if($recent['is_active'])
                                        <span class="badge badge-success sm">✓ Aktif Sekarang</span>
                                    @endif
                                    @if($recent['is_default'])
                                        <span class="badge badge-neutral sm">Default (.env)</span>
                                    @endif
                                </div>
                                <div style="font-size:12px;color:#64748b;font-family:monospace;word-break:break-all;">
                                    {{ $recent['path'] }}
                                </div>
                            </div>

                            <div style="display:flex;gap:8px;align-items:center;">
                                @if(!$recent['is_active'] && $recent['exists'])
                                    <form method="POST" action="{{ route('database.select') }}" style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="path" value="{{ $recent['path'] }}">
                                        <button type="submit" class="btn sm">
                                            Gunakan File Ini
                                        </button>
                                    </form>
                                @elseif(!$recent['exists'])
                                    <span class="badge badge-danger sm">File Tidak Ditemukan</span>
                                @endif

                                @if(!$recent['is_default'])
                                    <form method="POST" action="{{ route('database.remove_recent') }}" style="margin:0;" onsubmit="return confirm('Hapus file ini dari riwayat?');">
                                        @csrf
                                        <input type="hidden" name="path" value="{{ $recent['path'] }}">
                                        <button type="submit" class="btn outline-gray sm" title="Hapus dari daftar riwayat">
                                            ✕
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- TAB 3: UPLOAD FILE BARU --}}
        <div id="tab-upload" class="tab-pane">
            <p style="font-size:13px;color:#475569;margin-bottom:14px;">
                Upload file database Microsoft Access (<code>.accdb</code> atau <code>.mdb</code>) dari komputer Anda. File akan disimpan di direktori <code>storage/app/databases/</code> aplikasi.
            </p>

            <form method="POST" action="{{ route('database.upload') }}" enctype="multipart/form-data" id="uploadDbForm">
                @csrf
                <div style="border:2px dashed #cbd5e1;border-radius:10px;padding:24px;text-align:center;background:#f8fafc;margin-bottom:16px;">
                    <span style="font-size:36px;display:block;margin-bottom:8px;">📁</span>
                    <label for="access_file" style="cursor:pointer;font-weight:700;color:var(--primary);font-size:15px;display:inline-block;margin-bottom:6px;">
                        Klik untuk Memilih File Access
                    </label>
                    <input type="file" name="access_file" id="access_file" accept=".accdb,.mdb" required style="max-width:360px;margin:8px auto;display:block;">
                    <div id="selectedFileInfo" style="font-size:13px;color:#64748b;margin-top:8px;">
                        Format yang didukung: .accdb, .mdb
                    </div>
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                    <div style="font-size:12px;color:#64748b;max-width:600px;line-height:1.5;">
                        ℹ️ <b>Batas Maksimal Upload PHP:</b> {{ $uploadMax }} (POST max: {{ $postMax }}).<br>
                        Untuk file database berukuran ratusan MB atau GB, gunakan tab <b>"Path Lokal Komputer"</b> agar langsung dibuka tanpa batasan ukuran file.
                    </div>
                    <button type="submit" id="btnUploadSubmit" class="btn green" style="padding:10px 20px;">
                        ⬆️ Upload & Buka Database
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- DAFTAR TABEL ACCESS --}}
@if($tables)
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
        <h2 style="margin:0;">
            Tabel Access <span style="font-size:15px;color:#64748b;font-weight:normal;">({{ count($tables) }} tabel ditemukan)</span>
        </h2>
        <span style="font-size:13px;color:#64748b;">
            Klik nama tabel untuk melihat preview 50 record data
        </span>
    </div>
    <div class="grid">
        @foreach($tables as $t)
            <a class="table-card" href="{{ route('table', $t) }}" title="Lihat isi tabel {{ $t }}">
                <span class="table-name">{{ $t }}</span>
                <span class="table-action">Lihat Data &rarr;</span>
            </a>
        @endforeach
    </div>
</div>
@elseif($dbInfo['exists'])
<div class="card" style="text-align:center;padding:32px;color:#64748b;">
    <span style="font-size:32px;display:block;margin-bottom:8px;">📭</span>
    <b>Tidak ada tabel yang ditemukan dalam database ini.</b>
    <p style="margin:6px 0 0 0;font-size:13px;">Pastikan file database tidak kosong atau periksa pengaturan allowlist jika diaktifkan.</p>
</div>
@endif

{{-- FORM EXPORT DATA --}}
@if($tables)
<div class="card">
    <h2>Export Data</h2>
    <form id="exportForm" method="post" action="{{ route('export') }}">
        @csrf
        <input type="hidden" name="db_path" id="exportDbPath" value="{{ $dbInfo['path'] }}">
        <div class="row">
            <div>
                <label><b>Pilih Tabel</b></label>
                <select name="table" id="exportTable">
                    @foreach($tables as $t)
                        <option value="{{ $t }}" {{ old('table') == $t ? 'selected' : '' }}>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label><b>Format File</b></label>
                <select name="format" id="exportFormat">
                    <option value="csv" {{ old('format') == 'csv' ? 'selected' : '' }}>CSV (.csv / .zip) - Maks 1.000.000 baris/sheet (Auto-split)</option>
                    <option value="xlsx" {{ old('format') == 'xlsx' ? 'selected' : '' }}>Excel (.xlsx) - Maks 50.000 baris</option>
                    <option value="sql" {{ old('format') == 'sql' ? 'selected' : '' }}>SQL (.sql) - Satu File</option>
                    <option value="zip" {{ old('format') == 'zip' ? 'selected' : '' }}>SQL ZIP (.zip) - Chunk per File</option>
                </select>
            </div>
            <div>
                <label><b>Start / Offset</b></label>
                <input name="offset" id="exportOffset" type="number" min="0" value="{{ old('offset', 0) }}" placeholder="Mulai dari baris ke-0">
            </div>
            <div>
                <label><b>Limit (0 = semua data)</b></label>
                <input name="limit" id="exportLimit" type="number" min="0" value="{{ old('limit', 0) }}" placeholder="0 = tanpa batas">
            </div>
        </div>
        <br>
        <div class="row">
            <div>
                <label><b>Record per file SQL / batch chunk</b></label>
                <input name="chunk" id="exportChunk" type="number" min="1" value="{{ old('chunk', config('access.chunk_size', 10000)) }}">
            </div>
            <div>
                <label><b>Maks baris per Sheet / File CSV</b></label>
                <input name="max_rows_per_sheet" id="exportMaxRowsPerSheet" type="number" min="1000" step="1000" value="{{ old('max_rows_per_sheet', config('access.csv_max_rows_per_file', 1000000)) }}">
            </div>
        </div>
        <br>
        <div style="font-size:12px;color:#64748b;margin-bottom:14px;line-height:1.6;background:#f8fafc;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
            💡 <b>Tips Export Data Besar:</b><br>
            - Untuk tabel dengan jutaan baris, pilih format <b>CSV</b>. Setiap file/sheet otomatis dibatasi <b>maksimal 1.000.000 baris</b> (sesuai limit 1 sheet Microsoft Excel). Sisanya otomatis dialirkan ke sheet berikutnya (Part 01, Part 02, dst.) dan dikemas ke dalam ZIP sehingga aman dibuka di Excel tanpa data terpotong.<br>
            - Format <b>Excel (.xlsx)</b> dibatasi maksimal 50.000 baris per file agar komputer tidak kehabisan RAM.
        </div>
        <button id="btnStartExport" class="btn" style="font-size:15px;padding:10px 24px;">Mulai Export Data</button>
    </form>
</div>
@endif

<!-- LIVE PROGRESS CARD -->
<div id="progressCard" class="card" style="display:none;border:2px solid #2563eb;background:#f8fafc;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h2 style="margin:0;display:flex;align-items:center;gap:10px;">
            <span id="progressSpinner" style="display:inline-block;width:18px;height:18px;border:3px solid #2563eb;border-top-color:transparent;border-radius:50%;animation:spin 0.8s linear infinite;"></span>
            <span>Progress Export Data</span>
        </h2>
        <span id="badgeStatus" style="background:#dbeafe;color:#1e40af;font-size:12px;font-weight:bold;padding:5px 12px;border-radius:20px;">
            Sedang Memproses...
        </span>
    </div>

    <!-- PROGRESS BAR -->
    <div style="background:#e2e8f0;border-radius:999px;height:24px;overflow:hidden;position:relative;margin-bottom:14px;box-shadow:inset 0 1px 3px rgba(0,0,0,0.1);">
        <div id="progressBar" style="background:linear-gradient(90deg, #2563eb, #3b82f6);width:0%;height:100%;transition:width 0.3s ease;display:flex;align-items:center;justify-content:flex-end;padding-right:10px;box-sizing:border-box;">
            <span id="progressText" style="color:white;font-size:12px;font-weight:bold;text-shadow:0 1px 2px rgba(0,0,0,0.4);">0%</span>
        </div>
    </div>

    <!-- STATS GRID -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:12px;margin-bottom:14px;">
        <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
            <div style="font-size:11px;color:#64748b;font-weight:bold;text-transform:uppercase;">Tabel Target</div>
            <div id="statTable" style="font-size:15px;font-weight:bold;color:#0f172a;margin-top:4px;">-</div>
        </div>
        <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
            <div style="font-size:11px;color:#64748b;font-weight:bold;text-transform:uppercase;">Baris Diproses</div>
            <div id="statRows" style="font-size:15px;font-weight:bold;color:#0f172a;margin-top:4px;">0 / -</div>
        </div>
        <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
            <div style="font-size:11px;color:#64748b;font-weight:bold;text-transform:uppercase;">Batch / Chunk</div>
            <div id="statBatch" style="font-size:15px;font-weight:bold;color:#0f172a;margin-top:4px;">Menunggu...</div>
        </div>
        <div style="background:#fff;padding:12px;border-radius:8px;border:1px solid #e2e8f0;">
            <div style="font-size:11px;color:#64748b;font-weight:bold;text-transform:uppercase;">Waktu Berjalan</div>
            <div id="statTime" style="font-size:15px;font-weight:bold;color:#0f172a;margin-top:4px;">0s</div>
        </div>
    </div>

    <!-- STATUS LOG BOX -->
    <div id="statusBox" style="background:#0f172a;color:#f8fafc;padding:12px 14px;border-radius:8px;font-family:monospace;font-size:13px;line-height:1.5;min-height:22px;word-break:break-word;">
        Menghubungkan ke database Access...
    </div>

    <!-- ACTION BUTTONS -->
    <div style="display:flex;gap:10px;margin-top:16px;">
        <a id="btnDownload" href="#" style="display:none;background:#16a34a;color:white;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:bold;font-size:14px;">
            📥 Download File Sekarang
        </a>
        <button id="btnCancel" type="button" class="btn gray" style="padding:10px 16px;font-size:13px;">
            Batal
        </button>
        <button id="btnReset" type="button" class="btn" style="display:none;padding:10px 16px;font-size:13px;">
            Export Ulang / Tabel Lain
        </button>
    </div>
</div>

<script>
// Toggle DB Chooser Panel
const toggleBtn = document.getElementById('toggleDbChooser');
const chooserPanel = document.getElementById('dbChooserPanel');
if (toggleBtn && chooserPanel) {
    toggleBtn.addEventListener('click', function () {
        if (chooserPanel.style.display === 'none' || chooserPanel.style.display === '') {
            chooserPanel.style.display = 'block';
            chooserPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } else {
            chooserPanel.style.display = 'none';
        }
    });
}

// Tab Switcher
function switchDbTab(tabId, el) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    const target = document.getElementById(tabId);
    if (target) target.classList.add('active');
}

// Copy Current Path
function copyCurrentPath() {
    const pathText = document.getElementById('currentDbPath')?.innerText?.trim();
    if (pathText) {
        navigator.clipboard.writeText(pathText).then(() => {
            const btn = document.getElementById('btnCopyPath');
            const original = btn.innerText;
            btn.innerText = '✅ Tersalin!';
            setTimeout(() => { btn.innerText = original; }, 2000);
        });
    }
}

// File input change indicator
const fileInput = document.getElementById('access_file');
if (fileInput) {
    fileInput.addEventListener('change', function () {
        const info = document.getElementById('selectedFileInfo');
        if (this.files && this.files[0]) {
            const f = this.files[0];
            const sizeMb = (f.size / (1024 * 1024)).toFixed(2);
            info.innerHTML = `<b style="color:#0f172a;">${f.name}</b> (${sizeMb} MB)`;
        }
    });
}

// Scan Folder AJAX
function scanLocalFolder() {
    const folder = document.getElementById('scanFolderInput')?.value?.trim();
    if (!folder) {
        alert('Silakan masukkan path folder.');
        return;
    }

    const loading = document.getElementById('scanFolderLoading');
    const resultsBox = document.getElementById('scanFolderResults');
    const btn = document.getElementById('btnScanFolder');

    loading.style.display = 'block';
    resultsBox.style.display = 'none';
    btn.disabled = true;

    fetch("{{ route('database.scan') }}", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            "Accept": "application/json"
        },
        body: JSON.stringify({ folder: folder })
    })
    .then(res => res.json())
    .then(data => {
        loading.style.display = 'none';
        btn.disabled = false;
        resultsBox.style.display = 'block';

        if (!data.success) {
            resultsBox.innerHTML = `
                <div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;font-size:13px;">
                    ❌ <b>Gagal Scan:</b> ${data.message || 'Folder tidak dapat dibaca.'}
                </div>
            `;
            return;
        }

        if (!data.files || data.files.length === 0) {
            resultsBox.innerHTML = `
                <div style="background:#fff;border:1px solid #e2e8f0;padding:12px;border-radius:8px;font-size:13px;color:#64748b;text-align:center;">
                    Tidak ditemukan file <code>.accdb</code> atau <code>.mdb</code> di dalam folder: <b>${data.folder}</b>.
                </div>
            `;
            return;
        }

        let html = `
            <div style="font-size:12px;font-weight:700;color:#1e293b;margin-bottom:8px;">
                Ditemukan ${data.files.length} file Access di dalam folder:
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;">
        `;

        data.files.forEach(f => {
            const escapedPath = f.path.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            html += `
                <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid ${f.is_active ? '#3b82f6' : '#cbd5e1'};border-radius:8px;padding:10px 14px;gap:10px;">
                    <div>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <b style="font-size:14px;color:#0f172a;">${f.name}</b>
                            <span class="badge badge-info sm">${f.extension}</span>
                            <span class="badge badge-neutral sm">${f.size_human}</span>
                            ${f.is_active ? '<span class="badge badge-success sm">✓ Sedang Aktif</span>' : ''}
                        </div>
                        <div style="font-size:11px;color:#64748b;">Dimodifikasi: ${f.modified_at}</div>
                    </div>
                    <div>
                        <button type="button" class="btn sm ${f.is_active ? 'gray' : ''}" onclick="selectScannedFile('${escapedPath}')">
                            ${f.is_active ? 'Sedang Dipakai' : 'Pilih File Ini'}
                        </button>
                    </div>
                </div>
            `;
        });

        html += `</div>`;
        resultsBox.innerHTML = html;
    })
    .catch(err => {
        loading.style.display = 'none';
        btn.disabled = false;
        resultsBox.style.display = 'block';
        resultsBox.innerHTML = `
            <div style="background:#fee2e2;color:#991b1b;padding:10px 14px;border-radius:8px;font-size:13px;">
                ❌ Terjadi kesalahan saat memindai folder.
            </div>
        `;
    });
}

function selectScannedFile(filePath) {
    const input = document.getElementById('inputLocalPath');
    if (input) {
        input.value = filePath;
        // submit form
        input.closest('form').submit();
    }
}

// SSE EXPORT LOGIC
let eventSource = null;
let timerInterval = null;
let startTime = 0;

const exportForm = document.getElementById('exportForm');
if (exportForm) {
    exportForm.addEventListener('submit', function (e) {
        e.preventDefault();

        const table = document.getElementById('exportTable').value;
        const format = document.getElementById('exportFormat').value;
        const offset = document.getElementById('exportOffset').value || 0;
        const limit = document.getElementById('exportLimit').value || 0;
        const chunk = document.getElementById('exportChunk').value || 10000;
        const maxRowsPerSheet = document.getElementById('exportMaxRowsPerSheet')?.value || 1000000;
        const dbPath = document.getElementById('exportDbPath')?.value || '';

        // UI Initialization
        const progressCard = document.getElementById('progressCard');
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');
        const progressSpinner = document.getElementById('progressSpinner');
        const badgeStatus = document.getElementById('badgeStatus');
        const statTable = document.getElementById('statTable');
        const statRows = document.getElementById('statRows');
        const statBatch = document.getElementById('statBatch');
        const statTime = document.getElementById('statTime');
        const statusBox = document.getElementById('statusBox');
        const btnDownload = document.getElementById('btnDownload');
        const btnCancel = document.getElementById('btnCancel');
        const btnReset = document.getElementById('btnReset');
        const btnStart = document.getElementById('btnStartExport');

        progressCard.style.display = 'block';
        progressCard.scrollIntoView({ behavior: 'smooth' });

        progressBar.style.width = '0%';
        progressBar.style.background = 'linear-gradient(90deg, #2563eb, #3b82f6)';
        progressText.innerText = '0%';
        progressSpinner.style.display = 'inline-block';
        badgeStatus.style.background = '#dbeafe';
        badgeStatus.style.color = '#1e40af';
        badgeStatus.innerText = 'Sedang Memproses...';
        statTable.innerText = table + ' (' + format.toUpperCase() + ')';
        statRows.innerText = '0 / ...';
        statBatch.innerText = 'Menyiapkan batch...';
        statusBox.innerText = 'Menginisialisasi koneksi ODBC dan membaca tabel ' + table + '...';
        statusBox.style.color = '#f8fafc';
        btnDownload.style.display = 'none';
        btnReset.style.display = 'none';
        btnCancel.style.display = 'inline-block';
        btnStart.disabled = true;

        // Start Timer
        startTime = Date.now();
        clearInterval(timerInterval);
        timerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            statTime.innerText = elapsed + 's';
        }, 1000);

        // Build URL for SSE stream
        const params = new URLSearchParams({
            table: table,
            format: format,
            offset: offset,
            limit: limit,
            chunk: chunk,
            max_rows_per_sheet: maxRowsPerSheet,
            db_path: dbPath
        });

        if (eventSource) {
            eventSource.close();
        }

        eventSource = new EventSource("{{ route('export.progress') }}?" + params.toString());

        eventSource.onmessage = function (e) {
            try {
                const data = JSON.parse(e.data);

                if (data.event === 'start' || data.event === 'ready') {
                    statusBox.innerText = data.message;
                    if (data.total) {
                        statRows.innerText = '0 / ' + Number(data.total).toLocaleString('id-ID');
                    }
                } else if (data.event === 'progress') {
                    const percent = Math.min(100, Math.max(0, data.percent));
                    progressBar.style.width = percent + '%';
                    progressText.innerText = percent + '%';
                    statBatch.innerText = 'Batch #' + (data.batch || 1);
                    statRows.innerText = Number(data.processed).toLocaleString('id-ID') + ' / ' + Number(data.total).toLocaleString('id-ID');
                    statusBox.innerText = data.message;
                } else if (data.event === 'completed') {
                    clearInterval(timerInterval);
                    eventSource.close();

                    progressBar.style.width = '100%';
                    progressBar.style.background = '#16a34a';
                    progressText.innerText = '100%';
                    progressSpinner.style.display = 'none';
                    badgeStatus.style.background = '#dcfce7';
                    badgeStatus.style.color = '#166534';
                    badgeStatus.innerText = 'Selesai';
                    statusBox.innerText = data.message;
                    statRows.innerText = Number(data.processed).toLocaleString('id-ID') + ' baris selesai';

                    btnDownload.href = data.download_url;
                    btnDownload.style.display = 'inline-block';
                    btnCancel.style.display = 'none';
                    btnReset.style.display = 'inline-block';
                    btnStart.disabled = false;

                    // Auto trigger download
                    const tempLink = document.createElement('a');
                    tempLink.href = data.download_url;
                    tempLink.setAttribute('download', data.file_name);
                    document.body.appendChild(tempLink);
                    tempLink.click();
                    document.body.removeChild(tempLink);
                } else if (data.event === 'error') {
                    clearInterval(timerInterval);
                    eventSource.close();

                    progressSpinner.style.display = 'none';
                    badgeStatus.style.background = '#fee2e2';
                    badgeStatus.style.color = '#991b1b';
                    badgeStatus.innerText = 'Gagal';
                    progressBar.style.background = '#dc2626';
                    statusBox.innerText = '❌ ' + data.message;
                    statusBox.style.color = '#fca5a5';

                    btnCancel.style.display = 'none';
                    btnReset.style.display = 'inline-block';
                    btnStart.disabled = false;
                }
            } catch (err) {
                console.error('Error parsing SSE data:', err);
            }
        };

        eventSource.onerror = function (err) {
            clearInterval(timerInterval);
            if (eventSource) eventSource.close();
            progressSpinner.style.display = 'none';
            badgeStatus.style.background = '#fee2e2';
            badgeStatus.style.color = '#991b1b';
            badgeStatus.innerText = 'Terputus';
            btnCancel.style.display = 'none';
            btnReset.style.display = 'inline-block';
            btnStart.disabled = false;
        };
    });
}

const btnCancelEl = document.getElementById('btnCancel');
if (btnCancelEl) {
    btnCancelEl.addEventListener('click', function () {
        if (eventSource) {
            eventSource.close();
        }
        clearInterval(timerInterval);
        document.getElementById('progressSpinner').style.display = 'none';
        document.getElementById('badgeStatus').style.background = '#fef3c7';
        document.getElementById('badgeStatus').style.color = '#92400e';
        document.getElementById('badgeStatus').innerText = 'Dibatalkan';
        document.getElementById('statusBox').innerText = 'Proses export telah dibatalkan.';
        this.style.display = 'none';
        document.getElementById('btnReset').style.display = 'inline-block';
        document.getElementById('btnStartExport').disabled = false;
    });
}

const btnResetEl = document.getElementById('btnReset');
if (btnResetEl) {
    btnResetEl.addEventListener('click', function () {
        document.getElementById('progressCard').style.display = 'none';
        document.getElementById('exportForm').scrollIntoView({ behavior: 'smooth' });
    });
}
</script>
@endsection
