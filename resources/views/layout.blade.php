<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Access Data Exporter' }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; margin: 0; color: #1e293b; }
        .wrap { max-width: 1200px; margin: 35px auto; padding: 0 18px; }
        .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        h1, h2 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
        
        .table-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            transition: all 0.2s ease;
        }
        .table-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(37,99,235,0.1);
            transform: translateY(-1px);
        }
        .table-name {
            font-weight: 700;
            font-size: 14px;
            color: #1d4ed8;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
            min-width: 0;
        }
        .table-action {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 600;
            color: #2563eb;
            background: #eff6ff;
            padding: 5px 11px;
            border-radius: 6px;
            white-space: nowrap;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }
        .table-card:hover .table-action {
            background: #2563eb;
            color: #ffffff;
        }

        .table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table th, .table td { padding: 8px; border-bottom: 1px solid #eee; text-align: left; white-space: nowrap; }
        .table-wrap { overflow: auto; }
        a { color: #2563eb; text-decoration: none; }
        .btn { background: #2563eb; color: white; border: 0; border-radius: 8px; padding: 9px 14px; cursor: pointer; font-weight: 600; }
        .btn.gray { background: #64748b; }
        input, select { padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; width: 100%; box-sizing: border-box; }
        .row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; align-items: end; }
        .err { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('content')
    </div>
</body>
</html>
