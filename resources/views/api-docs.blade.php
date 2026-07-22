<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation</title>
    <style>
        :root {
            --bg-color: #0f111a;
            --sidebar-bg: #161925;
            --text-main: #e2e8f0;
            --text-muted: #94a3b8;
            --accent: #3b82f6;
            --accent-hover: #2563eb;
            --border: #1e293b;
            
            --method-get: #10b981;
            --method-post: #3b82f6;
            --method-put: #f59e0b;
            --method-delete: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            display: flex;
            min-height: 100vh;
            line-height: 1.6;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background-color: var(--sidebar-bg);
            border-right: 1px solid var(--border);
            padding: 2rem 1.5rem;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-logo {
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-logo span {
            color: var(--accent);
        }

        .nav-group {
            margin-bottom: 2rem;
        }

        .nav-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .nav-link {
            display: block;
            color: var(--text-main);
            text-decoration: none;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            transition: all 0.2s;
            margin-bottom: 0.25rem;
        }

        .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: white;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 3rem 4rem;
            width: calc(100% - 280px);
            max-width: 1200px;
        }

        .header-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            background: linear-gradient(to right, #fff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-desc {
            color: var(--text-muted);
            font-size: 1.125rem;
            margin-bottom: 3rem;
            max-width: 600px;
        }

        /* API Block */
        .api-block {
            background-color: var(--sidebar-bg);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            margin-bottom: 3rem;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .api-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .method-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .method-GET { background-color: rgba(16, 185, 129, 0.1); color: var(--method-get); }
        .method-POST { background-color: rgba(59, 130, 246, 0.1); color: var(--method-post); }
        .method-PUT { background-color: rgba(245, 158, 11, 0.1); color: var(--method-put); }
        .method-DELETE { background-color: rgba(239, 68, 68, 0.1); color: var(--method-delete); }

        .endpoint-url {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 1rem;
            color: white;
            font-weight: 500;
        }

        .auth-badge {
            margin-left: auto;
            font-size: 0.75rem;
            background: rgba(255,255,255,0.1);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            color: var(--text-muted);
        }

        .auth-badge.required {
            background: rgba(245, 158, 11, 0.1);
            color: var(--method-put);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .api-body {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        @media (max-width: 1024px) {
            .api-body { grid-template-columns: 1fr; }
        }

        .section-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: white;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-desc {
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
        }

        /* Parameters Table */
        .params-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .params-table th {
            text-align: left;
            padding: 0.75rem 0;
            color: var(--text-muted);
            font-weight: 500;
            border-bottom: 1px solid var(--border);
        }

        .params-table td {
            padding: 1rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }

        .params-table tr:last-child td { border-bottom: none; }

        .param-name {
            font-family: monospace;
            color: var(--accent);
            font-weight: 600;
        }

        .param-type { color: #a78bfa; font-size: 0.75rem; margin-left: 0.5rem; }
        .param-req { color: var(--method-delete); font-size: 0.7rem; text-transform: uppercase; font-weight: bold; margin-left: 0.5rem; }
        .param-opt { color: var(--text-muted); font-size: 0.7rem; text-transform: uppercase; margin-left: 0.5rem; }
        .param-desc { color: var(--text-muted); display: block; margin-top: 0.25rem; font-size: 0.8rem; }

        /* Code Block */
        .code-block {
            background-color: #0b0f19;
            border-radius: 0.5rem;
            padding: 1.25rem;
            overflow-x: auto;
            border: 1px solid var(--border);
            position: relative;
        }

        .code-block pre {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.875rem;
            color: #e2e8f0;
            margin: 0;
        }

        .copy-btn {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            cursor: pointer;
            transition: 0.2s;
        }
        
        .copy-btn:hover { background: rgba(255,255,255,0.2); }
    </style>
    <!-- Inter Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--accent);"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            API<span>Docs</span>
        </div>

        @foreach($apis as $groupName => $endpoints)
            <div class="nav-group">
                <div class="nav-title">{{ $groupName }}</div>
                @foreach($endpoints as $api)
                    <a href="#{{ Str::slug($api['endpoint']) }}" class="nav-link">
                        {{ $api['endpoint'] }}
                    </a>
                @endforeach
            </div>
        @endforeach
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <h1 class="header-title">API Reference</h1>
        <p class="header-desc">Explore the complete API reference for the platform. This documentation outlines all available endpoints, their required parameters, and expected responses.</p>

        @foreach($apis as $groupName => $endpoints)
            @foreach($endpoints as $api)
                <div class="api-block" id="{{ Str::slug($api['endpoint']) }}">
                    <div class="api-header">
                        <span class="method-badge method-{{ $api['method'] }}">{{ $api['method'] }}</span>
                        <span class="endpoint-url">{{ $api['endpoint'] }}</span>
                        
                        @if($api['requires_auth'])
                            <span class="auth-badge required">
                                <svg style="width:12px; height:12px; display:inline-block; vertical-align:middle; margin-right:4px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                Auth Required
                            </span>
                        @else
                            <span class="auth-badge">Public Route</span>
                        @endif
                    </div>
                    
                    <div class="api-body">
                        <!-- Left Side: Request Info -->
                        <div class="api-request">
                            <p class="section-desc">{{ $api['description'] }}</p>
                            
                            <h3 class="section-title">Parameters</h3>
                            @if(count($api['parameters']) > 0)
                                <table class="params-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 30%">Field</th>
                                            <th style="width: 70%">Example / Description</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($api['parameters'] as $param)
                                            <tr>
                                                <td>
                                                    <span class="param-name">{{ $param['name'] }}</span>
                                                    <br>
                                                    <span class="param-type">{{ $param['type'] }}</span>
                                                    @if($param['required'])
                                                        <span class="param-req">Required</span>
                                                    @else
                                                        <span class="param-opt">Optional</span>
                                                    @endif
                                                </td>
                                                <td>
                                                    @if(isset($param['example']))
                                                    <div style="margin-bottom: 0.5rem;">
                                                        <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Example:</span>
                                                        <code style="background: rgba(255,255,255,0.05); padding: 0.2rem 0.4rem; border-radius: 0.25rem; font-size: 0.8rem; color: #a78bfa;">{{ $param['example'] }}</code>
                                                    </div>
                                                    @endif
                                                    <span class="param-desc">{{ $param['description'] }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @else
                                <p style="color: var(--text-muted); font-size: 0.875rem;">No parameters required.</p>
                            @endif
                        </div>

                        <!-- Right Side: Response -->
                        <div class="api-response">
                            <h3 class="section-title">Example Response</h3>
                            <div class="code-block">
                                <button class="copy-btn" onclick="navigator.clipboard.writeText(this.nextElementSibling.innerText); this.innerText='Copied!'; setTimeout(() => this.innerText='Copy', 2000);">Copy</button>
                                <pre><code>{{ $api['response'] }}</code></pre>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        @endforeach
    </main>

</body>
</html>
