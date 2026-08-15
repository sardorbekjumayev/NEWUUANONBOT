<?php

declare(strict_types=1);

namespace PUAnonymous;

final class AdminView
{
    public static function render(array $config): void
    {
        $botUsername = htmlspecialchars($config['bot_username'] ?? 'Bot', ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Admin WebApp | PU Anonymous</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0d1117;
            --card-bg: rgba(22, 27, 34, 0.75);
            --card-border: rgba(255, 255, 255, 0.08);
            --text-main: #f0f6fc;
            --text-muted: #8b949e;
            --accent-purple: #8b5cf6;
            --accent-cyan: #06b6d4;
            --accent-pink: #ec4899;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --gradient-primary: linear-gradient(135deg, #6366f1 0%, #a855f7 50%, #ec4899 100%);
            --gradient-card: linear-gradient(180deg, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0.01) 100%);
            --radius-lg: 16px;
            --radius-md: 12px;
            --radius-sm: 8px;
            --font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: var(--font-family);
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
            padding-bottom: 40px;
            background-image: 
                radial-gradient(circle at 10% 10%, rgba(99, 102, 241, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 90% 90%, rgba(236, 72, 153, 0.15) 0%, transparent 40%);
            background-attachment: fixed;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 16px;
        }

        /* Header */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: var(--card-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            margin-bottom: 20px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-icon {
            width: 42px;
            height: 42px;
            border-radius: var(--radius-md);
            background: var(--gradient-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(168, 85, 247, 0.4);
        }

        .header-text h1 {
            font-size: 18px;
            font-weight: 700;
            background: linear-gradient(90deg, #fff, #a855f7);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header-text p {
            font-size: 12px;
            color: var(--text-muted);
        }

        .badge-admin {
            background: rgba(168, 85, 247, 0.2);
            color: #d8b4fe;
            border: 1px solid rgba(168, 85, 247, 0.4);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Navigation Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: none;
        }

        .tabs::-webkit-scrollbar {
            display: none;
        }

        .tab-btn {
            flex: 1;
            min-width: 110px;
            padding: 12px 14px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .tab-btn.active {
            background: var(--gradient-primary);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 4px 20px rgba(99, 102, 241, 0.4);
        }

        /* Section Panels */
        .panel {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .panel.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Cards & Grid */
        .grid-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: var(--card-bg);
            background-image: var(--gradient-card);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            backdrop-filter: blur(12px);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            background: rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .stat-info h4 {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 4px;
        }

        .stat-info .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-main);
        }

        .card {
            background: var(--card-bg);
            background-image: var(--gradient-card);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 20px;
            backdrop-filter: blur(12px);
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 16px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Forms & Inputs */
        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 12px 16px;
            background: rgba(13, 17, 23, 0.8);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            color: var(--text-main);
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s ease;
        }

        .form-input:focus, .form-select:focus {
            border-color: var(--accent-purple);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
        }

        /* Switch Toggle */
        .switch-group {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: rgba(13, 17, 23, 0.5);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            margin-bottom: 16px;
        }

        .switch-label {
            font-size: 14px;
            font-weight: 600;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 26px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #30363d;
            transition: .4s;
            border-radius: 34px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background: var(--gradient-primary);
        }

        input:checked + .slider:before {
            transform: translateX(22px);
        }

        /* Buttons */
        .btn {
            padding: 12px 20px;
            border-radius: var(--radius-md);
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: #fff;
            box-shadow: 0 4px 15px rgba(168, 85, 247, 0.3);
        }

        .btn-primary:hover {
            opacity: 0.95;
            box-shadow: 0 6px 20px rgba(168, 85, 247, 0.5);
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: var(--radius-sm);
        }

        /* Lists & Tags */
        .word-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .word-chip {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            border-radius: 20px;
            padding: 6px 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .word-chip:hover {
            border-color: var(--accent-purple);
            background: rgba(139, 92, 246, 0.1);
        }

        .chip-delete {
            cursor: pointer;
            color: var(--text-muted);
            font-size: 14px;
        }

        .chip-delete:hover {
            color: var(--accent-red);
        }

        .admin-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px;
            background: rgba(13, 17, 23, 0.6);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-md);
            margin-bottom: 10px;
        }

        .admin-id {
            font-family: monospace;
            font-size: 14px;
            color: var(--accent-cyan);
        }

        /* Access Denied */
        .access-denied {
            text-align: center;
            padding: 60px 20px;
        }

        .access-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }

        /* Toast Notice */
        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: #1f2937;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
            padding: 12px 24px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 500;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            transition: transform 0.3s ease;
            z-index: 1000;
        }

        .toast.show {
            transform: translateX(-50%) translateY(0);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-title">
                <div class="header-icon">🛡️</div>
                <div class="header-text">
                    <h1>PU Anonymous Admin</h1>
                    <p>@<?= $botUsername ?> Boshqaruv Paneli</p>
                </div>
            </div>
            <div class="badge-admin" id="userBadge">Verifying...</div>
        </div>

        <!-- Access Denied Screen (hidden by default) -->
        <div id="deniedScreen" class="card access-denied" style="display: none;">
            <div class="access-icon">⛔</div>
            <h2>Ruxsat berilmadi</h2>
            <p style="color: var(--text-muted); margin-top: 8px;">Ushbu Web App faqat adminlar uchun mo'ljallangan.</p>
        </div>

        <!-- Main Admin App UI -->
        <div id="appUI" style="display: none;">
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('dashboard')">📊 Dashboard</button>
                <button class="tab-btn" onclick="switchTab('ai')">🤖 AI & API Keys</button>
                <button class="tab-btn" onclick="switchTab('admins')">👥 Adminlar</button>
                <button class="tab-btn" onclick="switchTab('wordlist')">📝 Wordlist</button>
            </div>

            <!-- Tab 1: Dashboard -->
            <div id="tab-dashboard" class="panel active">
                <div class="grid-2">
                    <div class="stat-card">
                        <div class="stat-icon">⚡</div>
                        <div class="stat-info">
                            <h4>Bot Holati</h4>
                            <div class="stat-value" style="color: var(--accent-green);">Onlayn</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🤖</div>
                        <div class="stat-info">
                            <h4>Faol AI</h4>
                            <div class="stat-value" id="statActiveAi">Yuclanmoqda...</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-info">
                            <h4>Adminlar Soni</h4>
                            <div class="stat-value" id="statAdminsCount">0</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📝</div>
                        <div class="stat-info">
                            <h4>Wordlist So'zlar</h4>
                            <div class="stat-value" id="statWordlistCount">0</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">📌 Tizim ma'lumotlari</div>
                    <div style="margin-top: 16px; font-size: 13px; color: var(--text-muted); line-height: 1.6;">
                        <p>• Matnli xabarlar avtomatik AI (Gemini / Groq) orqali moderatorlik qilinadi.</p>
                        <p>• Local Wordlist mos kelsa xabar admin moderatsiyasiga yuboriladi.</p>
                        <p>• Barcha AI kalitlari va sozlamalarini <b>AI & API Keys</b> bo'limida yangilashingiz mumkin.</p>
                    </div>
                </div>
            </div>

            <!-- Tab 2: AI & API Keys -->
            <div id="tab-ai" class="panel">
                <div class="card">
                    <div class="card-title">✨ Google Gemini AI</div>
                    <div class="switch-group" style="margin-top: 16px;">
                        <span class="switch-label">Gemini AI Yoqish</span>
                        <label class="switch">
                            <input type="checkbox" id="geminiEnabled" onchange="toggleAiSwitch('gemini')">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gemini API Key</label>
                        <input type="password" id="geminiApiKey" class="form-input" placeholder="AIzaSy...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Gemini Model</label>
                        <input type="text" id="geminiModel" class="form-input" value="gemini-2.5-flash-lite">
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">⚡ Groq AI (Fallback)</div>
                    <div class="switch-group" style="margin-top: 16px;">
                        <span class="switch-label">Groq AI Yoqish</span>
                        <label class="switch">
                            <input type="checkbox" id="groqEnabled" onchange="toggleAiSwitch('groq')">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Groq API Key</label>
                        <input type="password" id="groqApiKey" class="form-input" placeholder="gsk_...">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Groq Model</label>
                        <input type="text" id="groqModel" class="form-input" value="llama-3.1-8b-instant">
                    </div>
                </div>

                <button class="btn btn-primary" style="width: 100%;" onclick="saveAiSettings()">💾 Sozlamalarni saqlash</button>
            </div>

            <!-- Tab 3: Admins -->
            <div id="tab-admins" class="panel">
                <div class="card">
                    <div class="card-title">➕ Yangi Admin Qo'shish</div>
                    <div style="display: flex; gap: 10px; margin-top: 16px;">
                        <input type="text" id="newAdminId" class="form-input" placeholder="Telegram User ID (masalan: 123456789)">
                        <button class="btn btn-primary" onclick="addAdmin()">Qo'shish</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-title">👥 Joriy Adminlar Royhati</div>
                    <div id="adminsList" style="margin-top: 16px;">Yuclanmoqda...</div>
                </div>
            </div>

            <!-- Tab 4: Wordlist CRUD -->
            <div id="tab-wordlist" class="panel">
                <div class="card">
                    <div class="card-title">➕ Yangi Taqiq so'z qo'shish</div>
                    <div style="display: flex; gap: 10px; margin-top: 16px;">
                        <input type="text" id="newWordInput" class="form-input" placeholder="Taqiqlangan so'z yoki ibora...">
                        <button class="btn btn-primary" onclick="addWord()">Qo'shish</button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <div class="card-title">📝 Taqiq so'zlar ro'yxati</div>
                        <input type="text" id="searchWordInput" class="form-input" placeholder="Qidirish..." style="max-width: 200px; padding: 6px 12px; font-size: 12px;" oninput="filterWords()">
                    </div>
                    <div class="word-grid" id="wordGrid">Yuclanmoqda...</div>
                </div>
            </div>
        </div>
    </div>

    <div id="toast" class="toast">Notice</div>

    <script>
        let initData = window.Telegram?.WebApp?.initData || '';
        let allWords = [];

        document.addEventListener('DOMContentLoaded', () => {
            if (window.Telegram?.WebApp) {
                window.Telegram.WebApp.ready();
                window.Telegram.WebApp.expand();
            }
            fetchStatus();
        });

        function showToast(msg) {
            const t = document.getElementById('toast');
            t.innerText = msg;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), 3000);
        }

        function switchTab(name) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
            
            event.currentTarget.classList.add('active');
            document.getElementById('tab-' + name).classList.add('active');
        }

        async function apiCall(endpoint, method = 'GET', body = null) {
            const headers = { 'Content-Type': 'application/json' };
            if (initData) {
                headers['X-Telegram-Init-Data'] = initData;
            }

            try {
                const res = await fetch(endpoint, {
                    method,
                    headers,
                    body: body ? JSON.stringify(body) : null
                });
                const data = await res.json();
                return { status: res.status, data };
            } catch (err) {
                return { status: 500, data: { ok: false, error: err.message } };
            }
        }

        async function fetchStatus() {
            const { status, data } = await apiCall('/api/admin/status');
            if (status === 403 || !data.ok) {
                document.getElementById('deniedScreen').style.display = 'block';
                document.getElementById('appUI').style.display = 'none';
                document.getElementById('userBadge').innerText = 'Access Denied';
                return;
            }

            document.getElementById('deniedScreen').style.display = 'none';
            document.getElementById('appUI').style.display = 'block';
            document.getElementById('userBadge').innerText = 'Admin Verified';

            // Dashboard stats
            const activeAi = data.ai.gemini_enabled ? 'Gemini AI' : (data.ai.groq_enabled ? 'Groq AI' : 'O\'chirilgan');
            document.getElementById('statActiveAi').innerText = activeAi;
            document.getElementById('statAdminsCount').innerText = data.admins_count;
            document.getElementById('statWordlistCount').innerText = data.wordlist_count;

            // AI inputs
            document.getElementById('geminiEnabled').checked = data.ai.gemini_enabled;
            document.getElementById('groqEnabled').checked = data.ai.groq_enabled;
            document.getElementById('geminiModel').value = data.ai.gemini_model;
            document.getElementById('groqModel').value = data.ai.groq_model;

            loadAdmins();
            loadWordlist();
        }

        function toggleAiSwitch(type) {
            if (type === 'gemini' && document.getElementById('geminiEnabled').checked) {
                document.getElementById('groqEnabled').checked = false;
            } else if (type === 'groq' && document.getElementById('groqEnabled').checked) {
                document.getElementById('geminiEnabled').checked = false;
            }
        }

        async function saveAiSettings() {
            const body = {
                gemini_enabled: document.getElementById('geminiEnabled').checked,
                gemini_api_key: document.getElementById('geminiApiKey').value,
                gemini_model: document.getElementById('geminiModel').value,
                groq_enabled: document.getElementById('groqEnabled').checked,
                groq_api_key: document.getElementById('groqApiKey').value,
                groq_model: document.getElementById('groqModel').value,
            };

            const { data } = await apiCall('/api/admin/ai', 'POST', body);
            if (data.ok) {
                showToast('✅ AI sozlamalari yangilandi');
                fetchStatus();
            } else {
                showToast('❌ ' + (data.error || 'Xatolik yuz berdi'));
            }
        }

        async function loadAdmins() {
            const { data } = await apiCall('/api/admin/admins');
            if (!data.ok) return;

            const list = document.getElementById('adminsList');
            if (data.admins.length === 0) {
                list.innerHTML = '<p style="color: var(--text-muted); font-size: 13px;">Adminlar mavjud emas</p>';
                return;
            }

            list.innerHTML = data.admins.map(id => `
                <div class="admin-item">
                    <span class="admin-id">🆔 ${id}</span>
                    <button class="btn btn-danger btn-sm" onclick="removeAdmin('${id}')">O'chirish</button>
                </div>
            `).join('');
        }

        async function addAdmin() {
            const input = document.getElementById('newAdminId');
            const id = input.value.trim();
            if (!id) return showToast('⚠️ User ID kiriting');

            const { data } = await apiCall('/api/admin/admins', 'POST', { action: 'add', telegram_id: id });
            if (data.ok) {
                input.value = '';
                showToast('✅ Admin qo\'shildi');
                loadAdmins();
                fetchStatus();
            } else {
                showToast('❌ ' + (data.error || 'Xatolik'));
            }
        }

        async function removeAdmin(id) {
            if (!confirm(`Admin ID ${id} ni o'chirishga ishonchingiz komilmi?`)) return;

            const { data } = await apiCall('/api/admin/admins', 'POST', { action: 'remove', telegram_id: id });
            if (data.ok) {
                showToast('🗑 Admin o\'chirildi');
                loadAdmins();
                fetchStatus();
            } else {
                showToast('❌ ' + (data.error || 'Xatolik'));
            }
        }

        async function loadWordlist() {
            const { data } = await apiCall('/api/admin/wordlist');
            if (!data.ok) return;

            allWords = data.words || [];
            renderWordGrid(allWords);
        }

        function renderWordGrid(words) {
            const grid = document.getElementById('wordGrid');
            if (words.length === 0) {
                grid.innerHTML = '<p style="color: var(--text-muted); font-size: 13px;">Taqiq so\'zlar yo'q</p>';
                return;
            }

            grid.innerHTML = words.map(w => `
                <div class="word-chip">
                    <span>${escapeHtml(w)}</span>
                    <span class="chip-delete" onclick="deleteWord('${escapeHtml(w)}')">✕</span>
                </div>
            `).join('');
        }

        function filterWords() {
            const q = document.getElementById('searchWordInput').value.toLowerCase().trim();
            if (!q) {
                renderWordGrid(allWords);
                return;
            }
            renderWordGrid(allWords.filter(w => w.toLowerCase().includes(q)));
        }

        async function addWord() {
            const input = document.getElementById('newWordInput');
            const word = input.value.trim();
            if (!word) return showToast('⚠️ So\'z kiriting');

            const { data } = await apiCall('/api/admin/wordlist', 'POST', { action: 'add', word });
            if (data.ok) {
                input.value = '';
                showToast('✅ So\'z qo\'shildi');
                loadWordlist();
                fetchStatus();
            } else {
                showToast('❌ ' + (data.error || 'Xatolik'));
            }
        }

        async function deleteWord(word) {
            const { data } = await apiCall('/api/admin/wordlist', 'POST', { action: 'delete', word });
            if (data.ok) {
                showToast('🗑 So\'z o\'chirildi');
                loadWordlist();
                fetchStatus();
            } else {
                showToast('❌ ' + (data.error || 'Xatolik'));
            }
        }

        function escapeHtml(str) {
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>
        <?php
    }
}
