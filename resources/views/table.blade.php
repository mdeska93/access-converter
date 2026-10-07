@extends('layout')

@section('content')
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
        <a href="{{ route('home') }}" class="btn outline-gray sm">
            &larr; Kembali ke Beranda
        </a>
        @if(!empty($dbInfo['filename']))
            <span class="badge badge-info" title="{{ $dbInfo['path'] }}">
                📁 Database: {{ $dbInfo['filename'] }}
            </span>
        @endif
    </div>

    <h1>Tabel: {{ $table }}</h1>
    <p>Menampilkan pratinjau 50 record data pertama dari tabel <code>{{ $table }}</code>.</p>

    @if(!empty($error))
        <div class="alert alert-danger">{{ $error }}</div>
    @endif

    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    @foreach($data['columns'] as $c)
                        <th>{{ $c }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($data['rows'] as $row)
                    <tr>
                        @foreach($data['columns'] as $c)
                            <td>{{ is_scalar($row[$c] ?? null) ? $row[$c] : json_encode($row[$c] ?? null) }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ count($data['columns']) ?: 1 }}" style="text-align:center;padding:24px;color:#64748b;">
                            Tidak ada data dalam tabel ini.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:18px;">
        <a href="{{ route('home') }}" class="btn gray">
            &larr; Kembali untuk Export Data
        </a>
    </div>
</div>
@endsection
