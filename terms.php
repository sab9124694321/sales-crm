<?php
session_start();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Контракт Охотника</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 50%, #d1dce8 100%);
            min-height: 100vh;
            color: #1e293b;
            overflow-x: hidden;
        }
        .stars {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none; z-index: 0;
            background-image: radial-gradient(circle at 20% 50%, rgba(99,102,241,0.08) 0%, transparent 50%),
                              radial-gradient(circle at 80% 80%, rgba(168,85,247,0.06) 0%, transparent 50%);
        }
        .container {
            max-width: 480px; margin: 0 auto; padding: 20px;
            position: relative; z-index: 1;
        }
        .header {
            text-align: center; padding: 40px 0 30px;
        }
        .logo {
            width: 80px; height: 80px; margin: 0 auto 20px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 24px; display: flex; align-items: center; justify-content: center;
            font-size: 36px; box-shadow: 0 10px 40px rgba(99,102,241,0.3);
        }
        .header h1 {
            font-family: 'Orbitron', sans-serif; font-size: 24px; font-weight: 900;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            margin-bottom: 8px;
        }
        .header p { color: #64748b; font-size: 14px; }
        .card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.5);
            box-shadow: 0 4px 24px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            padding: 24px; margin-bottom: 16px;
        }
        .card-title {
            font-family: 'Orbitron', sans-serif; font-size: 14px; font-weight: 700;
            color: #4f46e5; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
        }
        .card-text { font-size: 14px; line-height: 1.7; color: #475569; }
        .card-text strong { color: #1e293b; }
        .reward-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 16px;
        }
        .reward-item {
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            border-radius: 12px; padding: 16px; text-align: center;
            border: 1px solid rgba(99,102,241,0.1);
        }
        .reward-icon { font-size: 28px; margin-bottom: 8px; }
        .reward-value {
            font-family: 'Orbitron', sans-serif; font-size: 18px; font-weight: 700;
            color: #4f46e5;
        }
        .reward-label { font-size: 12px; color: #64748b; margin-top: 4px; }
        .checkbox-group { margin-top: 20px; }
        .checkbox-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px; margin-bottom: 10px;
            background: #f8fafc; border-radius: 12px;
            border: 2px solid #e2e8f0; cursor: pointer;
            transition: all 0.2s;
        }
        .checkbox-item.active {
            border-color: #6366f1; background: #eef2ff;
        }
        .checkbox-item input { display: none; }
        .check-circle {
            width: 22px; height: 22px; border-radius: 50%;
            border: 2px solid #cbd5e1; flex-shrink: 0; margin-top: 2px;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.2s; font-size: 12px; color: transparent;
        }
        .checkbox-item.active .check-circle {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-color: transparent; color: white;
        }
        .checkbox-text { font-size: 13px; color: #475569; line-height: 1.5; }
        .checkbox-text strong { color: #1e293b; }
        .btn {
            width: 100%; padding: 16px; border: none; border-radius: 14px;
            font-family: 'Orbitron', sans-serif; font-size: 15px; font-weight: 700;
            cursor: pointer; transition: all 0.3s; margin-top: 8px;
            text-transform: uppercase; letter-spacing: 1px;
        }
        .btn:disabled {
            background: #cbd5e1; color: #94a3b8; cursor: not-allowed;
            transform: none; box-shadow: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: white; box-shadow: 0 8px 32px rgba(99,102,241,0.3);
        }
        .btn-primary:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 40px rgba(99,102,241,0.4);
        }
        .footer { text-align: center; padding: 20px; color: #94a3b8; font-size: 12px; }
        .level-bar {
            display: flex; align-items: center; gap: 8px; margin-top: 12px;
        }
        .level-progress {
            flex: 1; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;
        }
        .level-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #6366f1, #8b5cf6); border-radius: 4px; }
    </style>
</head>
<body>
    <div class="stars"></div>
    <div class="container">
        <div class="header">
            <div class="logo">🎯</div>
            <h1>КОНТРАКТ ОХОТНИКА</h1>
            <p>Программа лояльности для активных участников</p>
        </div>

        <div class="card">
            <div class="card-title">⚡ УСЛОВИЯ ПРОГРАММЫ</div>
            <div class="card-text">
                Добро пожаловать в команду Охотников! Ты находишь потенциальных клиентов, а мы превращаем их в партнёров. 
                <strong>За каждый успешный лид</strong> ты получаешь баллы XP, которые можно обменять на ценные призы.
            </div>
        </div>

        <div class="card">
            <div class="card-title">🏆 НАГРАДЫ ЗА ЛИДЫ</div>
            <div class="reward-grid">
                <div class="reward-item">
                    <div class="reward-icon">🎯</div>
                    <div class="reward-value">+50</div>
                    <div class="reward-label">XP за лид</div>
                </div>
                <div class="reward-item">
                    <div class="reward-icon">✅</div>
                    <div class="reward-value">+200</div>
                    <div class="reward-label">XP за одобрение</div>
                </div>
                <div class="reward-item">
                    <div class="reward-icon">⭐</div>
                    <div class="reward-value">+500</div>
                    <div class="reward-label">XP за реферала</div>
                </div>
                <div class="reward-item">
                    <div class="reward-icon">🏆</div>
                    <div class="reward-value">ТОП-10</div>
                    <div class="reward-label">Призовой фонд</div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-title">📋 ПРАВИЛА</div>
            <div class="card-text">
                • Передавай только реальные данные заведений<br>
                • Получи согласие контактного лица на передачу номера<br>
                • Указывай корректный ИНН (10 или 12 цифр)<br>
                • Не создавай дубли — проверяй ИНН перед отправкой<br>
                • Чем больше лидов — тем выше уровень и призы
            </div>
        </div>

        <div class="card">
            <div class="card-title">🔒 СОГЛАСИЕ</div>
            <div class="checkbox-group">
                <label class="checkbox-item" onclick="toggleCheck(this)">
                    <input type="checkbox" name="agree1" id="agree1">
                    <div class="check-circle">✓</div>
                    <div class="checkbox-text">
                        Я согласен с <strong>обработкой персональных данных</strong> и подтверждаю, что мне исполнилось 18 лет
                    </div>
                </label>
                <label class="checkbox-item" onclick="toggleCheck(this)">
                    <input type="checkbox" name="agree2" id="agree2">
                    <div class="check-circle">✓</div>
                    <div class="checkbox-text">
                        Я подтверждаю, что при вводе контактных данных <strong>получено согласие</strong> на их передачу третьим лицам
                    </div>
                </label>
                <label class="checkbox-item" onclick="toggleCheck(this)">
                    <input type="checkbox" name="agree3" id="agree3">
                    <div class="check-circle">✓</div>
                    <div class="checkbox-text">
                        Я ознакомлен с <strong>правилами программы</strong> и обязуюсь соблюдать их
                    </div>
                </label>
            </div>
        </div>

        <button class="btn btn-primary" id="acceptBtn" disabled onclick="acceptContract()">
            🚀 Принять контракт
        </button>

        <div class="footer">
            © 2026 Программа «Охотник»<br>
            Все права защищены
        </div>
    </div>

    <script>
        function toggleCheck(el) {
            const input = el.querySelector('input');
            input.checked = !input.checked;
            el.classList.toggle('active', input.checked);
            checkAll();
        }
        function checkAll() {
            const all = document.querySelectorAll('input[type="checkbox"]');
            const checked = document.querySelectorAll('input[type="checkbox"]:checked');
            const btn = document.getElementById('acceptBtn');
            btn.disabled = checked.length !== all.length;
        }
        function acceptContract() {
            localStorage.setItem('hunter_terms_accepted', '1');
            window.location.href = 'hunter_register.php';
        }
    </script>
</body>
</html>
