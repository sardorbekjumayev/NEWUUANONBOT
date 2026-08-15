<!DOCTYPE html>
<html lang="uz" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>University Anonymous Community — Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-primary: #0f172a;
            --bg-card: #1e293b;
            --bg-hover: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent-blue: #38bdf8;
            --accent-green: #22c55e;
            --accent-red: #ef4444;
            --accent-amber: #f59e0b;
            --border-color: #334155;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background-color: var(--bg-card);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            padding: 20px;
        }

        .brand {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--accent-blue);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .nav-link:hover, .nav-link.active {
            background-color: var(--bg-hover);
            color: var(--text-primary);
        }

        .badge {
            background-color: var(--accent-blue);
            color: #0f172a;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .stat-card .title {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .stat-card .value {
            font-size: 1.875rem;
            font-weight: 700;
        }

        /* Data Tables */
        .table-container {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th, td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background-color: var(--bg-hover);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* Action Buttons */
        .btn {
            padding: 8px 14px;
            border-radius: 6px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
            transition: opacity 0.2s ease;
        }

        .btn-success { background-color: var(--accent-green); color: white; }
        .btn-danger { background-color: var(--accent-red); color: white; }
        .btn-warning { background-color: var(--accent-amber); color: white; }
        .btn:hover { opacity: 0.85; }

        /* Login Modal */
        #login-modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .login-box {
            background: var(--bg-card);
            padding: 40px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            width: 100%;
            max-width: 360px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 0.875rem;
            color: var(--text-secondary);
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            color: white;
            box-sizing: border-box;
        }
    </style>
</head>
<body>

    <!-- Login Modal -->
    <div id="login-modal">
        <div class="login-box">
            <h2 style="margin-top:0; color: var(--accent-blue);">🔐 Admin Kirish</h2>
            <form id="login-form">
                <div class="form-group">
                    <label>Foydalanuvchi nomi</label>
                    <input type="text" id="username" value="admin" required>
                </div>
                <div class="form-group">
                    <label>Parol</label>
                    <input type="password" id="password" value="admin123456" required>
                </div>
                <button type="submit" class="btn btn-success" style="width: 100%; padding: 12px;">Kirish</button>
            </form>
            <p id="login-error" style="color: var(--accent-red); margin-top: 15px; display: none;"></p>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="brand">🛡 Anonim Admin</div>
        <div class="nav-link active" onclick="switchTab('queue')">
            <span>🛡 Moderatsiya navbat</span>
            <span class="badge" id="badge-pending">0</span>
        </div>
        <div class="nav-link" onclick="switchTab('published')">
            <span>✅ Chop etilganlar</span>
        </div>
        <div class="nav-link" onclick="switchTab('rejected')">
            <span>❌ Rad etilganlar</span>
        </div>
        <div class="nav-link" onclick="switchTab('audit')">
            <span>📜 Audit loglar</span>
        </div>
        <div style="margin-top: auto;">
            <button class="btn btn-danger" style="width: 100%;" onclick="logout()">Chiqish</button>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="main-content">
        <div class="header">
            <h1 id="page-title">Moderatsiya navbat</h1>
            <button class="btn btn-warning" onclick="loadDashboard()">🔄 Yangilash</button>
        </div>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="title">Jami Xabarlar</div>
                <div class="value" id="stat-total">0</div>
            </div>
            <div class="stat-card">
                <div class="title">Moderatsiyada</div>
                <div class="value" id="stat-pending" style="color: var(--accent-amber);">0</div>
            </div>
            <div class="stat-card">
                <div class="title">Chop Etilgan</div>
                <div class="value" id="stat-approved" style="color: var(--accent-green);">0</div>
            </div>
            <div class="stat-card">
                <div class="title">Bugungi Xabarlar</div>
                <div class="value" id="stat-today" style="color: var(--accent-blue);">0</div>
            </div>
        </div>

        <!-- Data Content Area -->
        <div class="table-container">
            <table id="data-table">
                <thead>
                    <tr id="table-head">
                        <th>ID</th>
                        <th>Kategoriya</th>
                        <th>Mazmun</th>
                        <th>AI Holati</th>
                        <th>Amallar</th>
                    </tr>
                </thead>
                <tbody id="table-body">
                    <tr><td colspan="5" style="text-align: center;">Ma'lumotlar yuklanmoqda...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        let currentTab = 'queue';

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const u = document.getElementById('username').value;
            const p = document.getElementById('password').value;

            const res = await fetch('api.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: u, password: p })
            });
            const data = await res.json();

            if (data.success) {
                document.getElementById('login-modal').style.display = 'none';
                loadDashboard();
            } else {
                const err = document.getElementById('login-error');
                err.innerText = data.error;
                err.style.display = 'block';
            }
        });

        async function loadDashboard() {
            fetchStats();
            if (currentTab === 'queue') loadQueue();
            else if (currentTab === 'published') loadPublished();
            else if (currentTab === 'rejected') loadRejected();
            else if (currentTab === 'audit') loadAudit();
        }

        async function fetchStats() {
            try {
                const res = await fetch('api.php?action=stats');
                const data = await res.json();
                if (data.success) {
                    document.getElementById('stat-total').innerText = data.stats.total;
                    document.getElementById('stat-pending').innerText = data.stats.pending;
                    document.getElementById('stat-approved').innerText = data.stats.approved;
                    document.getElementById('stat-today').innerText = data.stats.today;
                    document.getElementById('badge-pending').innerText = data.stats.pending;
                }
            } catch (e) { console.error(e); }
        }

        async function loadQueue() {
            document.getElementById('page-title').innerText = "Moderatsiya Navbati";
            const res = await fetch('api.php?action=queue');
            const data = await res.json();
            const body = document.getElementById('table-body');
            body.innerHTML = '';

            if (data.queue.length === 0) {
                body.innerHTML = '<tr><td colspan="5" style="text-align: center;">Moderatsiya navbati bo\'sh! 🎉</td></tr>';
                return;
            }

            data.queue.forEach(item => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><b>${item.public_id}</b></td>
                    <td><span class="badge">${item.category}</span></td>
                    <td>${item.sanitized_content}</td>
                    <td><small>${item.ai_status} (${Math.round(item.ai_score * 100)}%)</small></td>
                    <td>
                        <button class="btn btn-success" onclick="approveItem(${item.id})">✅ Tasdiqlash</button>
                        <button class="btn btn-danger" onclick="rejectItem(${item.id})">❌ Rad etish</button>
                    </td>
                `;
                body.appendChild(tr);
            });
        }

        async function approveItem(id) {
            await fetch('api.php?action=approve', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            loadDashboard();
        }

        async function rejectItem(id) {
            await fetch('api.php?action=reject', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, reason: 'Moderator qarori' })
            });
            loadDashboard();
        }

        function switchTab(tab) {
            currentTab = tab;
            loadDashboard();
        }

        async function logout() {
            await fetch('api.php?action=logout');
            location.reload();
        }

        // Auto initial check
        fetch('api.php?action=stats').then(res => {
            if (res.ok) {
                document.getElementById('login-modal').style.display = 'none';
                loadDashboard();
            }
        });
    </script>
</body>
</html>
