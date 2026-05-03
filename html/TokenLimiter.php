<?php
/**
 * Класс для ограничения использования токенов GigaChat
 */
class TokenLimiter {
    // Лимиты бесплатного тарифа GigaChat (ориентировочно)
    const DAILY_LIMIT = 10000;      // 10 000 токенов в день
    const MONTHLY_LIMIT = 250000;   // 250 000 токенов в месяц
    const WARNING_THRESHOLD = 80;    // 80% - предупреждение
    
    private $db;
    
    public function __construct($pdo) {
        $this->db = $pdo;
        $this->initTable();
    }
    
    private function initTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS token_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date DATE NOT NULL,
                tokens_used INTEGER DEFAULT 0,
                requests_count INTEGER DEFAULT 0,
                UNIQUE(date)
            )
        ");
    }
    
    /**
     * Проверяет, можно ли сделать запрос
     * @return array ['allowed' => bool, 'message' => string, 'remaining' => int]
     */
    public function checkLimit() {
        $today = date('Y-m-d');
        $month_start = date('Y-m-01');
        
        // Статистика за сегодня
        $stmt = $this->db->prepare("SELECT tokens_used FROM token_usage WHERE date = ?");
        $stmt->execute([$today]);
        $today_usage = $stmt->fetch();
        $today_tokens = $today_usage ? $today_usage['tokens_used'] : 0;
        
        // Статистика за месяц
        $stmt = $this->db->prepare("
            SELECT SUM(tokens_used) as total FROM token_usage 
            WHERE date >= ? AND date <= ?
        ");
        $stmt->execute([$month_start, $today]);
        $month_usage = $stmt->fetch();
        $month_tokens = $month_usage['total'] ?? 0;
        
        $remaining_today = self::DAILY_LIMIT - $today_tokens;
        $remaining_month = self::MONTHLY_LIMIT - $month_tokens;
        
        $allowed = ($remaining_today > 0 && $remaining_month > 0);
        $remaining = min($remaining_today, $remaining_month);
        
        $message = '';
        if (!$allowed) {
            if ($remaining_today <= 0) {
                $message = "⚠️ Дневной лимит токенов исчерпан. Попробуйте завтра.";
            } elseif ($remaining_month <= 0) {
                $message = "⚠️ Месячный лимит токенов исчерпан. Бесплатный лимит обновится 1 числа следующего месяца.";
            }
        } elseif ($remaining < self::DAILY_LIMIT * (100 - self::WARNING_THRESHOLD) / 100) {
            $percent = round((self::DAILY_LIMIT - $remaining) / self::DAILY_LIMIT * 100);
            $message = "📊 Использовано $percent% дневного лимита. Осталось ~$remaining токенов.";
        }
        
        return [
            'allowed' => $allowed,
            'message' => $message,
            'remaining_today' => $remaining_today,
            'remaining_month' => $remaining_month,
            'today_used' => $today_tokens,
            'month_used' => $month_tokens
        ];
    }
    
    /**
     * Регистрирует использование токенов
     * @param int $tokens Количество использованных токенов
     * @return bool
     */
    public function recordUsage($tokens) {
        $today = date('Y-m-d');
        
        $stmt = $this->db->prepare("
            INSERT INTO token_usage (date, tokens_used, requests_count)
            VALUES (?, ?, 1)
            ON CONFLICT(date) DO UPDATE SET
            tokens_used = tokens_used + ?,
            requests_count = requests_count + 1
        ");
        return $stmt->execute([$today, $tokens, $tokens]);
    }
    
    /**
     * Получает статистику использования
     * @return array
     */
    public function getStats() {
        $today = date('Y-m-d');
        $month_start = date('Y-m-01');
        
        $stmt = $this->db->prepare("SELECT tokens_used, requests_count FROM token_usage WHERE date = ?");
        $stmt->execute([$today]);
        $today_data = $stmt->fetch();
        
        $stmt = $this->db->prepare("
            SELECT SUM(tokens_used) as total_tokens, SUM(requests_count) as total_requests
            FROM token_usage WHERE date >= ? AND date <= ?
        ");
        $stmt->execute([$month_start, $today]);
        $month_data = $stmt->fetch();
        
        return [
            'today' => [
                'tokens' => $today_data['tokens_used'] ?? 0,
                'requests' => $today_data['requests_count'] ?? 0,
                'limit' => self::DAILY_LIMIT,
                'percent' => round(($today_data['tokens_used'] ?? 0) / self::DAILY_LIMIT * 100)
            ],
            'month' => [
                'tokens' => $month_data['total_tokens'] ?? 0,
                'requests' => $month_data['total_requests'] ?? 0,
                'limit' => self::MONTHLY_LIMIT,
                'percent' => round(($month_data['total_tokens'] ?? 0) / self::MONTHLY_LIMIT * 100)
            ]
        ];
    }
    
    /**
     * Оценивает количество токенов в тексте
     * @param string $text
     * @return int
     */
    public static function estimateTokens($text) {
        // Для кириллицы ~2.5 символа на токен
        return round(mb_strlen($text) / 2.5);
    }
}
?>
