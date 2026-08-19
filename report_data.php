<?php
function getReportData($year, $month, $territory_filter = 0) {
    global $pdo;
    require_once 'db.php';

    function getProductCounts($pdo, $tabel, $date_from, $date_to) {
        $sql = "
            SELECT
                COALESCE(SUM(CASE WHEN product = 'ТЭ' THEN 1 ELSE 0 END), 0) AS mass,
                COALESCE(SUM(CASE WHEN product = 'Смарт' THEN 1 ELSE 0 END), 0) AS keyv,
                COALESCE(SUM(CASE WHEN product = 'ПОС' THEN 1 ELSE 0 END), 0) AS kas
            FROM inn_records
            WHERE employee_tabel = ? AND DATE(sale_date) BETWEEN ? AND ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tabel, $date_from, $date_to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['mass' => (int)$row['mass'], 'keyv' => (int)$row['keyv'], 'kas' => (int)$row['kas']];
    }

    function calcStaz($date) {
        if (empty($date)) return '000/00';
        try {
            $d1 = new DateTime($date);
            $d2 = new DateTime();
            $diff = $d1->diff($d2);
            return sprintf('%03d/%02d', $diff->y, $diff->m);
        } catch (Exception $e) {
            return '000/00';
        }
    }

    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $date_from = "$year-$month-01";
    $date_to   = "$year-$month-$days_in_month";

    $today = new DateTime();
    $current_year = (int)$today->format('Y');
    $current_month = (int)$today->format('m');
    $current_day = (int)$today->format('d');
    $max_day = ($year == $current_year && $month == $current_month) ? $current_day : $days_in_month;
    $display_days = range(1, $max_day);
    $days_reverse = array_reverse($display_days);
    $weekdays_ru = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];

    $sql = "
        SELECT u.tabel_number, u.full_name, u.territory_id, u.head_tabel,
               u.position_start_date, u.created_at,
               t.name AS territory_name, h.full_name AS head_name
        FROM users u
        LEFT JOIN territories t ON u.territory_id = t.id
        LEFT JOIN users h ON u.head_tabel = h.tabel_number
        WHERE u.role = 'manager' AND u.is_active = 1
    ";
    if ($territory_filter > 0) {
        $sql .= " AND u.territory_id = " . (int)$territory_filter;
    }
    $sql .= " ORDER BY t.name, h.full_name, u.full_name";
    $managers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if (empty($managers)) die('Нет активных менеджеров');

    $plans = [];
    $period = sprintf('%04d-%02d', $year, $month);
    $stmt = $pdo->prepare("SELECT tabel_number, contracts_plan FROM plans WHERE period = ?");
    $stmt->execute([$period]);
    while ($r = $stmt->fetch()) {
        $plans[(string)$r['tabel_number']] = (int)$r['contracts_plan'];
    }

    $sales = [];
    $absences = [];
    foreach ($managers as $m) {
        $t = (string)$m['tabel_number'];
        foreach ($display_days as $d) {
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $sales[$t][$date_str] = getProductCounts($pdo, $t, $date_str, $date_str);
        }
        $stmt = $pdo->prepare("SELECT absence_date FROM employee_absences WHERE employee_tabel = ? AND absence_date BETWEEN ? AND ?");
        $stmt->execute([$t, $date_from, $date_to]);
        $abs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $absences[$t] = array_fill_keys($abs, true);
    }

    $fact_month = [];
    foreach ($sales as $t => $days) {
        $total = 0;
        foreach ($days as $cnt) {
            $total += $cnt['mass'] + $cnt['keyv'] + $cnt['kas'];
        }
        $fact_month[$t] = $total;
    }

    $structure = [];
    foreach ($managers as $m) {
        $terr_id = (int)($m['territory_id'] ?? 0);
        $terr_name = $m['territory_name'] ?? 'Без территории';
        $head_name = $m['head_name'] ?? 'Без руководителя';
        $tabel_key = (string)$m['tabel_number'];

        if (!isset($structure[$terr_id])) {
            $structure[$terr_id] = [
                'name' => $terr_name,
                'heads' => [],
                'daily_totals' => array_fill_keys($display_days, ['mass'=>0,'keyv'=>0,'kas'=>0]),
                'total_plan' => 0,
                'total_fact' => 0,
                'total_rr' => 0,
            ];
        }
        if (!isset($structure[$terr_id]['heads'][$head_name])) {
            $structure[$terr_id]['heads'][$head_name] = [
                'managers' => [],
                'total_plan' => 0,
                'total_fact' => 0,
                'total_rr' => 0,
            ];
        }
        $structure[$terr_id]['heads'][$head_name]['managers'][] = array_merge($m, ['tabel_key' => $tabel_key]);
    }

    foreach ($structure as $terr_id => &$terr) {
        $terr_plan = 0;
        $terr_fact = 0;
        $terr_daily = array_fill_keys($display_days, ['mass'=>0,'keyv'=>0,'kas'=>0]);

        foreach ($terr['heads'] as $head_name => &$head_group) {
            $head_plan = 0;
            $head_fact = 0;

            foreach ($head_group['managers'] as &$m) {
                $t = $m['tabel_key'];
                if (empty($t)) continue;

                $plan = $plans[$t] ?? 0;
                $fact = $fact_month[$t] ?? 0;
                $m['plan'] = $plan;
                $m['fact'] = $fact;
                $m['rr'] = $plan > 0 ? round(($fact / $plan) * 100) : 0;
                $start_date = (!empty($m['position_start_date'])) ? $m['position_start_date'] : ($m['created_at'] ?? '');
                $m['staz'] = calcStaz($start_date);

                $head_plan += $plan;
                $head_fact += $fact;

                foreach ($display_days as $d) {
                    $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                    $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['mass'=>0,'keyv'=>0,'kas'=>0];
                    $terr_daily[$d]['mass'] += $cnt['mass'];
                    $terr_daily[$d]['keyv'] += $cnt['keyv'];
                    $terr_daily[$d]['kas']  += $cnt['kas'];
                }
            }

            $head_group['total_plan'] = $head_plan;
            $head_group['total_fact'] = $head_fact;
            $head_group['total_rr'] = $head_plan > 0 ? round(($head_fact / $head_plan) * 100) : 0;

            $terr_plan += $head_plan;
            $terr_fact += $head_fact;
        }

        $terr['total_plan'] = $terr_plan;
        $terr['total_fact'] = $terr_fact;
        $terr['total_rr'] = $terr_plan > 0 ? round(($terr_fact / $terr_plan) * 100) : 0;
        $terr['daily_totals'] = $terr_daily;
    }
    unset($terr, $head_group, $m);

    return [
        'structure' => $structure,
        'year' => $year,
        'month' => $month,
        'display_days' => $display_days,
        'days_reverse' => $days_reverse,
        'sales' => $sales,
        'absences' => $absences,
        'fact_month' => $fact_month,
        'plans' => $plans,
        'weekdays_ru' => $weekdays_ru,
    ];
}