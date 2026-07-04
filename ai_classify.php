<?php
function classifyEmployee(PDO $pdo, string $tabel, int $days = 10): int {
    $stmt = $pdo->prepare("
        SELECT calls, calls_answered, meetings, contracts,
               registrations, smart_cash, pos_systems, inn_leads, teams
        FROM daily_reports
        WHERE tabel_number = ?
        ORDER BY report_date DESC
        LIMIT ?
    ");
    $stmt->execute([$tabel, $days]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < 3) return 1; // недостаточно данных → Охотник

    $totals = ['calls' => 0, 'answered' => 0, 'meetings' => 0, 'contracts' => 0,
               'smart' => 0, 'tea' => 0, 'pos' => 0];
    foreach ($rows as $r) {
        $totals['calls'] += (int)$r['calls'];
        $totals['answered'] += (int)$r['calls_answered'];
        $totals['meetings'] += (int)$r['meetings'];
        $totals['contracts'] += (int)$r['contracts'];
        $totals['smart'] += (int)$r['smart_cash'];
        $totals['tea'] += (int)$r['inn_leads'];
        $totals['pos'] += (int)$r['pos_systems'];
    }

    $cnt = count($rows);
    $avgCalls = $totals['calls'] / $cnt;
    $avgAnswered = $totals['answered'] / $cnt;
    $convAnsweredToMeeting = $totals['answered'] > 0 ? $totals['meetings'] / $totals['answered'] : 0;
    $convMeetingToContract = $totals['meetings'] > 0 ? $totals['contracts'] / $totals['meetings'] : 0;
    $crossSales = $totals['smart'] + $totals['tea'] + $totals['pos'];

    if ($crossSales > ($cnt * 1.5)) return 4; // Кросс-продавец
    if ($convMeetingToContract > 0.6 && $convAnsweredToMeeting > 0.5) return 3; // Коммуникатор
    if ($avgAnswered > ($avgCalls * 0.7) && $convAnsweredToMeeting < 0.3) return 2; // Настойчивый
    return 1; // Охотник
}
