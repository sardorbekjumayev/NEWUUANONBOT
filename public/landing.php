<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>University Anonymous Community — Anonim Muloqot Platformasi</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0f172a;
            --accent: #38bdf8;
            --accent-green: #22c55e;
            --bg-card: #1e293b;
            --text-light: #f8fafc;
            --text-gray: #94a3b8;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--primary);
            color: var(--text-light);
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 8%;
            border-bottom: 1px solid #334155;
        }

        .logo {
            font-size: 1.4rem;
            font-weight: 800;
            color: var(--accent);
        }

        .hero {
            padding: 100px 8% 80px;
            text-align: center;
            max-width: 900px;
            margin: 0 auto;
        }

        .hero h1 {
            font-size: 3.2rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 24px;
            background: linear-gradient(to right, #38bdf8, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero p {
            font-size: 1.25rem;
            color: var(--text-gray);
            margin-bottom: 40px;
        }

        .cta-group {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 16px 32px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            text-decoration: none;
            transition: transform 0.2s, opacity 0.2s;
        }

        .btn-primary {
            background-color: var(--accent);
            color: #0f172a;
        }

        .btn-secondary {
            background-color: var(--bg-card);
            color: var(--text-light);
            border: 1px solid #334155;
        }

        .btn:hover {
            transform: translateY(-2px);
            opacity: 0.95;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            padding: 60px 8%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .feature-card {
            background-color: var(--bg-card);
            border: 1px solid #334155;
            padding: 30px;
            border-radius: 16px;
        }

        .feature-card h3 {
            font-size: 1.3rem;
            color: var(--accent);
            margin-top: 0;
        }

        .privacy-box {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border: 1px solid var(--accent);
            padding: 40px;
            border-radius: 20px;
            margin: 60px 8%;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
        }

        footer {
            text-align: center;
            padding: 40px;
            border-top: 1px solid #334155;
            color: var(--text-gray);
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

    <header>
        <div class="logo">🛡 University Anon</div>
        <a href="https://t.me/UUAnonBot" class="btn btn-primary" style="padding: 10px 20px; font-size: 0.95rem;">Botga o'tish</a>
    </header>

    <section class="hero">
        <h1>Universitetingizda anonim fikr bildiring.</h1>
        <p>Fikr, savol, taklif yoki muammoni anonim yuboring. Shaxsingiz boshqa foydalanuvchilar va moderatorlarga ko'rsatilmaydi.</p>
        <div class="cta-group">
            <a href="https://t.me/UUAnonBot" class="btn btn-primary">🤫 Anonim xabar yuborish</a>
            <a href="https://t.me/UUAnonBot" class="btn btn-secondary">📢 Kanalga o'tish</a>
        </div>
    </section>

    <section class="features">
        <div class="feature-card">
            <h3>🔒 100% Identifikatsiya Himoyasi</h3>
            <p>Sizning Telegram username, ism-sharifingiz, profil rasmingiz va ID ma'lumotingiz tizimda yashiriladi hamda ko'rsatib berilmaydi.</p>
        </div>
        <div class="feature-card">
            <h3>🤖 AI Moderatsiya Hizmati</h3>
            <p>Yuborilgan har bir xabar avval sun'iy intellekt filtri orqali tekshiriladi. Telefon raqamlar va shaxsiy ma'lumotlar avtomatik yashiriladi.</p>
        </div>
        <div class="feature-card">
            <h3>🗑 O'chirish Huquqi</h3>
            <p>O'z anonim xabaringizni istalgan vaqtda botdagi <b>[📋 Mening xabarlarim]</b> bo'limi orqali o'chirib tashlashingiz mumkin.</p>
        </div>
    </section>

    <section class="privacy-box">
        <h2 style="color: var(--accent); margin-top:0;">Maxfiylik va Anonymity Kafolati</h2>
        <p>Platforma boshqa foydalanuvchilar va moderatorlarga sizning shaxsingizni ko'rsatmaydi. Moderatsiya guruhida xabaringiz mutlaqo yangi anonim karta ko'rinishida hosil qilinadi, original xabaringiz yo'naltirilmaydi (forward qilinmaydi).</p>
    </section>

    <footer>
        &copy; <?php echo date('Y'); ?> University Anonymous Community. Barcha huquqlar himoyalangan.
    </footer>

</body>
</html>
