-- Таблица задач из Ритм (без ПДН!)
CREATE TABLE IF NOT EXISTS epk_tasks (
    task_id TEXT PRIMARY KEY,
    user_tabel TEXT NOT NULL,
    product TEXT DEFAULT 'Торговый эквайринг',
    status TEXT DEFAULT 'Назначена',
    next_call_date DATETIME,
    imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_epk_user ON epk_tasks(user_tabel);
CREATE INDEX IF NOT EXISTS idx_epk_status ON epk_tasks(status);
CREATE INDEX IF NOT EXISTS idx_epk_nextcall ON epk_tasks(next_call_date);

-- Таблица комментариев к звонкам (без ПДН!)
CREATE TABLE IF NOT EXISTS call_comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    comment_text TEXT NOT NULL,
    call_result TEXT DEFAULT 'think',
    next_call_date DATETIME,
    deal_readiness INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_cc_task ON call_comments(task_id);
CREATE INDEX IF NOT EXISTS idx_cc_user ON call_comments(user_id);
CREATE INDEX IF NOT EXISTS idx_cc_created ON call_comments(created_at);

-- Таблица очереди на контроль РОПа
CREATE TABLE IF NOT EXISTS rop_control_queue (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    tabel TEXT NOT NULL,
    fraud_score INTEGER DEFAULT 0,
    comment_text TEXT,
    status TEXT DEFAULT 'На проверке',
    rop_comment TEXT,
    rop_action TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    checked_at DATETIME
);

CREATE INDEX IF NOT EXISTS idx_rop_status ON rop_control_queue(status);
CREATE INDEX IF NOT EXISTS idx_rop_user ON rop_control_queue(user_id);
CREATE INDEX IF NOT EXISTS idx_rop_tabel ON rop_control_queue(tabel);

-- Таблица статистики менеджеров (для аналитики фрода)
CREATE TABLE IF NOT EXISTS manager_call_stats (
    user_id INTEGER PRIMARY KEY,
    tabel TEXT NOT NULL,
    total_calls INTEGER DEFAULT 0,
    valid_calls INTEGER DEFAULT 0,
    fraud_flags INTEGER DEFAULT 0,
    last_call_date DATE,
    avg_comment_length INTEGER DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
