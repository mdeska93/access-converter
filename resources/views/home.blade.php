@extends('layout')

@section('content')
<div class="card">
    <h1>Microsoft Access Data Exporter</h1>
    <p>Browse database Access lokal dan export menjadi Excel, CSV, atau SQL chunks dengan live progress status.</p>
    @if($error)
        <div class="err">{{ $error }}</div>
    @endif
    @if($errors->any())
        <div class="err" style="margin-top:10px;">
            <ul style="margin:0;padding-left:20px;">
                @foreach($errors->all() as $err)
                    <li>{{ $err }}</li>
                @endforeach
            </ul>
        </div>
    @endif
</div>

@if($tables)
<div class="card">
    <h2>Tabel Access</h2>
    <div class="grid">
        @foreach($tables as $t)
            <a class="table-card" href="{{ route('table', $t) }}" title="{{ $t }}">
                <span class="table-name">{{ $t }}</span>
                <span class="table-action">Lihat Data &rarr;</span>
            </a>
        @endforeach
    </div>
</div>
@endif

<div class="card">
    <h2>Export Data</h2>
    <form id="exportForm" method="post" action="{{ route('export') }}">
        @csrf
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
        <div style="font-size:12px;color:#64748b;margin-bottom:14px;line-height:1.6;">
            💡 <b>Tips Export Data Besar:</b><br>
            - Untuk tabel dengan jutaan baris (seperti <code>anggota_keluarga</code> atau <code>keluarga</code>), pilih format <b>CSV</b>. Setiap file/sheet otomatis dibatasi <b>maksimal 1.000.000 baris</b> (sesuai batas maksimal 1 sheet Microsoft Excel). Sisanya otomatis dialirkan ke sheet/part berikutnya (Part 01, Part 02, dst.) dan dikemas ke dalam ZIP sehingga aman dibuka di Excel tanpa data terpotong.<br>
            - Format <b>Excel (.xlsx)</b> dibatasi maksimal 50.000 baris per file agar komputer tidak kehabisan RAM.
        </div>
        <button id="btnStartExport" class="btn" style="font-size:15px;padding:10px 24px;">Mulai Export Data</button>
    </form>
</div>

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

<style>
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<script>
let eventSource = null;
let timerInterval = null;
let startTime = 0;

document.getElementById('exportForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const table = document.getElementById('exportTable').value;
    const format = document.getElementById('exportFormat').value;
    const offset = document.getElementById('exportOffset').value || 0;
    const limit = document.getElementById('exportLimit').value || 0;
    const chunk = document.getElementById('exportChunk').value || 10000;
    const maxRowsPerSheet = document.getElementById('exportMaxRowsPerSheet')?.value || 1000000;

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
        max_rows_per_sheet: maxRowsPerSheet
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

document.getElementById('btnCancel').addEventListener('click', function () {
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

document.getElementById('btnReset').addEventListener('click', function () {
    document.getElementById('progressCard').style.display = 'none';
    document.getElementById('exportForm').scrollIntoView({ behavior: 'smooth' });
});
</script>
@endsection
