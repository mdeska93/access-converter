<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Microsoft Access Data Exporter' }}</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #eff6ff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --success: #16a34a;
            --success-light: #dcfce7;
            --danger: #dc2626;
            --danger-light: #fee2e2;
            --warning: #d97706;
            --warning-light: #fef3c7;
        }

        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg-page);
            margin: 0;
            color: var(--text-main);
            line-height: 1.5;
        }
        .wrap { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 22px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        h1 { margin: 0 0 8px 0; font-size: 26px; font-weight: 800; color: #0f172a; }
        h2 { margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #1e293b; }
        h3 { margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #334155; }
        p { margin: 0 0 12px 0; color: var(--text-muted); }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            letter-spacing: 0.3px;
        }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-danger { background: #fee2e2; color: #b91c1c; }
        .badge-info { background: #dbeafe; color: #1d4ed8; }
        .badge-warning { background: #fef3c7; color: #b45309; }
        .badge-neutral { background: #f1f5f9; color: #475569; }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: var(--primary);
            color: white;
            border: 0;
            border-radius: 8px;
            padding: 10px 16px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .btn:hover { background: var(--primary-hover); transform: translateY(-1px); }
        .btn:active { transform: translateY(0); }
        .btn.gray { background: #64748b; }
        .btn.gray:hover { background: #475569; }
        .btn.outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        .btn.outline:hover {
            background: var(--primary-light);
        }
        .btn.outline-gray {
            background: transparent;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .btn.outline-gray:hover {
            background: #f1f5f9;
            color: #0f172a;
        }
        .btn.danger { background: var(--danger); }
        .btn.danger:hover { background: #b91c1c; }
        .btn.green { background: var(--success); }
        .btn.green:hover { background: #15803d; }
        .btn.sm { padding: 6px 12px; font-size: 12px; border-radius: 6px; }

        /* Inputs & Form */
        input, select {
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            width: 100%;
            box-sizing: border-box;
            font-size: 14px;
            color: #1e293b;
            background: #fff;
            transition: border-color 0.2s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }
        .row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            align-items: end;
        }

        /* Alerts */
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .alert-success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
        .alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .alert-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }

        /* Table Card Grid */
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

        /* Table Preview */
        .table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .table th, .table td { padding: 9px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
        .table th { background: #f8fafc; font-weight: 700; color: #475569; position: sticky; top: 0; }
        .table-wrap { overflow: auto; border: 1px solid #e2e8f0; border-radius: 8px; max-height: 550px; }

        /* Tabs UI */
        .tabs-nav {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 18px;
        }
        .tab-btn {
            background: transparent;
            border: 0;
            border-bottom: 3px solid transparent;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: -2px;
        }
        .tab-btn:hover { color: var(--primary); }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; animation: fadeIn 0.2s ease; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(3px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="wrap">
        @yield('content')
    </div>
</body>
</html>
