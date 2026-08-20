<?php
ini_set('memory_limit', '256M');
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { die('Unauthorized'); }
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['head', 'territory_head', 'admin', 'terman'])) die('Доступ запрещён.');

// ── ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ──
function getProductCounts($pdo, $tabel, $date_from, $date_to) {
    $sql = "
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_key = 1 THEN 1 ELSE 0 END) AS keyv,
            SUM(CASE WHEN product IN ('ПОС', 'Смарт') THEN 1 ELSE 0 END) AS kas,
            SUM(CASE WHEN station_type = 'target' THEN 1 ELSE 0 END) AS target
        FROM inn_records
        WHERE employee_tabel = ?
          AND DATE(sale_date) BETWEEN ? AND ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tabel, $date_from, $date_to]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'total'  => (int) $row['total'],
        'keyv'   => (int) $row['keyv'],
        'kas'    => (int) $row['kas'],
        'target' => (int) $row['target']
    ];
}

function countWorkingDays($start, $end) {
    $start = new DateTime($start);
    $end = new DateTime($end);
    $end->modify('+1 day');
    $days = 0;
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    foreach ($period as $dt) {
        $dayOfWeek = (int) $dt->format('N');
        if ($dayOfWeek < 6) $days++;
    }
    return $days;
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

// ── Параметры ─────────────────────────────────────────────
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d', strtotime('-1 day'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d', strtotime('-1 day'));
}
$today = date('Y-m-d');
if ($selected_date > $today) {
    $selected_date = $today;
}

$year = (int) date('Y', strtotime($selected_date));
$month = (int) date('m', strtotime($selected_date));
$max_day = (int) date('d', strtotime($selected_date));

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
if ($max_day > $days_in_month) $max_day = $days_in_month;
$today_day = (int) date('d');
$today_month = (int) date('m');
$today_year = (int) date('Y');
if ($year == $today_year && $month == $today_month && $max_day > $today_day) {
    $max_day = $today_day;
}
if ($max_day < 1) $max_day = 1;

$display_days = range(1, $max_day);
$period = sprintf('%04d-%02d', $year, $month);
$date_from = sprintf('%04d-%02d-01', $year, $month);
$date_to   = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);

$territory_filter = isset($_GET['territory']) ? (int) $_GET['territory'] : 0;

// ── Получение менеджеров ──────────────────────────────────
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
    $sql .= " AND u.territory_id = " . (int) $territory_filter;
}
$sql .= " ORDER BY t.name, h.full_name, u.full_name";
$managers = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (empty($managers)) die('Нет активных менеджеров');

// ── Планы ──────────────────────────────────────────────────
$plans = [];
$stmt = $pdo->prepare("SELECT tabel_number, contracts_plan FROM plans WHERE period = ?");
$stmt->execute([$period]);
while ($r = $stmt->fetch()) {
    $t = (string)$r['tabel_number'];
    $plans[$t] = (int)$r['contracts_plan'];
}

// ── Сбор продаж, отсутствий и целевых ──────────────────
$sales = [];
$absences = [];
foreach ($managers as $m) {
    $t = trim((string)$m['tabel_number']);
    foreach ($display_days as $d) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $sales[$t][$date_str] = getProductCounts($pdo, $t, $date_str, $date_str);
    }
    $stmt = $pdo->prepare("
        SELECT absence_date 
        FROM employee_absences 
        WHERE TRIM(employee_tabel) = ? 
          AND DATE(absence_date) BETWEEN ? AND ?
    ");
    $stmt->execute([$t, $date_from, $date_to]);
    $abs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $absences[$t] = array_fill_keys($abs, true);
}

// ── Факт за месяц и целевые ─────────────────────────────
$fact_month = [];
$target_month = [];
foreach ($sales as $t => $days) {
    $total = 0;
    $target = 0;
    foreach ($days as $cnt) {
        $total += $cnt['total'];
        $target += $cnt['target'];
    }
    $fact_month[$t] = $total;
    $target_month[$t] = $target;
}

// ── ГРУППИРОВКА ────────────────────────────────────────────
$structure = [];
foreach ($managers as $m) {
    $terr_id   = (int) ($m['territory_id'] ?? 0);
    $terr_name = $m['territory_name'] ?? 'Без территории';
    $head_name = $m['head_name'] ?? 'Без руководителя';
    $head_tabel = (string) ($m['head_tabel'] ?? '');
    $tabel_key = trim((string)$m['tabel_number']);

    if (!isset($structure[$terr_id])) {
        $structure[$terr_id] = [
            'name'  => $terr_name,
            'heads' => [],
            'daily_totals' => array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]),
            'total_plan' => 0,
            'total_fact' => 0,
            'total_target'=> 0,
            'total_rr'   => 0,
            'total_vp'   => 0,
            'total_cs'   => 0,
        ];
    }
    if (!isset($structure[$terr_id]['heads'][$head_name])) {
        $structure[$terr_id]['heads'][$head_name] = [
            'head_tabel' => $head_tabel,
            'managers' => [],
            'total_plan' => 0,
            'total_fact' => 0,
            'total_target'=> 0,
            'total_rr'   => 0,
            'total_vp'   => 0,
            'total_cs'   => 0,
        ];
    }
    $structure[$terr_id]['heads'][$head_name]['managers'][] = array_merge($m, ['tabel_key' => $tabel_key]);
}

// ── ВЫЧИСЛЕНИЕ ИТОГОВ ──────────────────────────────────────
$grand_plan = 0;
$grand_fact = 0;
$grand_target = 0;
$grand_daily = array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);

$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end = date('Y-m-t', strtotime($month_start));
$total_working_days = countWorkingDays($month_start, $month_end);
$today_date = date('Y-m-d');
$working_days_passed = countWorkingDays($month_start, $today_date);

foreach ($structure as $terr_id => &$terr) {
    $terr_plan = 0;
    $terr_fact = 0;
    $terr_target = 0;
    $terr_daily = array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);

    foreach ($terr['heads'] as $head_name => &$head_group) {
        $head_plan = 0;
        $head_fact = 0;
        $head_target = 0;

        foreach ($head_group['managers'] as &$m) {
            $t = $m['tabel_key'];
            if (empty($t)) continue;

            $plan = $plans[$t] ?? 0;
            $fact = $fact_month[$t] ?? 0;
            $target = $target_month[$t] ?? 0;

            $m['plan'] = $plan;
            $m['fact'] = $fact;
            $m['target'] = $target;
            $m['vp']   = $plan > 0 ? round(($fact / $plan) * 100) : 0;
            $m['rr']   = ($working_days_passed > 0) ? round(($fact / $working_days_passed) * $total_working_days) : 0;
            $start_date = (!empty($m['position_start_date'])) ? $m['position_start_date'] : ($m['created_at'] ?? '');
            $m['staz'] = calcStaz($start_date);

            $head_plan += $plan;
            $head_fact += $fact;
            $head_target += $target;

            foreach ($display_days as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                $terr_daily[$d]['total'] += $cnt['total'];
                $terr_daily[$d]['keyv'] += $cnt['keyv'];
                $terr_daily[$d]['kas']  += $cnt['kas'];
                $terr_daily[$d]['target'] += $cnt['target'];
                $grand_daily[$d]['total'] += $cnt['total'];
                $grand_daily[$d]['keyv'] += $cnt['keyv'];
                $grand_daily[$d]['kas']  += $cnt['kas'];
                $grand_daily[$d]['target'] += $cnt['target'];
            }
        }

        $head_group['total_plan'] = $head_plan;
        $head_group['total_fact'] = $head_fact;
        $head_group['total_target'] = $head_target;
        $head_group['total_cs'] = $head_target;
        $head_group['total_rr'] = ($working_days_passed > 0 && $head_fact > 0) ? round(($head_fact / $working_days_passed) * $total_working_days) : 0;
        $head_group['total_vp'] = $head_plan > 0 ? round(($head_fact / $head_plan) * 100) : 0;

        $terr_plan += $head_plan;
        $terr_fact += $head_fact;
        $terr_target += $head_target;
    }

    $terr['total_plan'] = $terr_plan;
    $terr['total_fact'] = $terr_fact;
    $terr['total_target'] = $terr_target;
    $terr['total_cs'] = $terr_target;
    $terr['total_rr'] = ($working_days_passed > 0 && $terr_fact > 0) ? round(($terr_fact / $working_days_passed) * $total_working_days) : 0;
    $terr['total_vp'] = $terr_plan > 0 ? round(($terr_fact / $terr_plan) * 100) : 0;
    $terr['daily_totals'] = $terr_daily;

    $grand_plan += $terr_plan;
    $grand_fact += $terr_fact;
    $grand_target += $terr_target;
}
unset($terr, $head_group, $m);
$grand_rr = ($working_days_passed > 0 && $grand_fact > 0) ? round(($grand_fact / $working_days_passed) * $total_working_days) : 0;
$grand_vp = $grand_plan > 0 ? round(($grand_fact / $grand_plan) * 100) : 0;
$grand_cs = $grand_target;

$days_reverse = array_reverse($display_days);

// ── Формирование PDF ─────────────────────────────────────
require_once 'vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);

$html = '<html><head><meta charset="UTF-8"><style>
    body{font-family:DejaVu Sans, sans-serif;font-size:6px; margin:0; padding:0;}
    table{border-collapse:collapse;width:100%;font-size:6px;}
    th,td{border:1px solid #333;padding:1px 1px;text-align:center;}
    th{background:#f0f0f0;font-weight:bold;}
    .head-row td{background:#f3e5f5;font-weight:bold;}
    .totals-row td{font-weight:bold;background:#fff8e1;}
    .grand-totals td{font-weight:bold;background:#ffecb3;}
    .weekend{background:#f5f5f5;}
    .absence-mark{background:#e0e0e0;color:#555;}
    .name-col{text-align:left;}
    .red{background:#ff6b6b;color:#4a0000;}
    .yellow{background:#ffd93d;color:#5a3e00;}
    .green{background:#51cf66;color:#003d00;}
</style></head><body>';

$html .= '<h2 style="font-size:10px;margin:2px 0;">📋 Ежедневные продажи: по ГОСБ</h2>';
$html .= '<p style="font-size:8px;margin:2px 0;">Период: ' . date('F Y', strtotime($selected_date)) . ' (по ' . $selected_date . ')</p>';

// Сводная таблица
$html .= '<h3 style="font-size:9px;margin:4px 0;">📊 Сводка по ГОСБ</h3>';
$html .= '<table>';
$html .= '<thead><tr><th>ГОСБ</th><th>Начальник отдела</th><th>План</th><th>Факт</th><th>RR</th><th>ВП</th><th>ЦС</th>';
foreach ($days_reverse as $d) {
    $html .= '<th colspan="4">' . $d . '</th>';
}
$html .= '</tr><tr><th></th><th></th><th></th><th></th><th></th><th></th><th></th>';
foreach ($days_reverse as $d) {
    $html .= '<th>ИНН</th><th>Кл</th><th>Кс</th><th>ЦС</th>';
}
$html .= '</tr></thead><tbody>';

foreach ($structure as $terr_id => $terr) {
    $daily = $terr['daily_totals'] ?? array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);
    foreach ($terr['heads'] as $head_name => $head_group) {
        $head_daily = array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);
        foreach ($head_group['managers'] as $m) {
            $t = $m['tabel_key'];
            if (empty($t)) continue;
            foreach ($display_days as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                $head_daily[$d]['total'] += $cnt['total'];
                $head_daily[$d]['keyv'] += $cnt['keyv'];
                $head_daily[$d]['kas']  += $cnt['kas'];
                $head_daily[$d]['target'] += $cnt['target'];
            }
        }
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($terr['name']) . '</td>';
        $html .= '<td>' . htmlspecialchars($head_name) . '</td>';
        $html .= '<td>' . $head_group['total_plan'] . '</td>';
        $html .= '<td>' . $head_group['total_fact'] . '</td>';
        $html .= '<td>' . $head_group['total_rr'] . '</td>';
        $html .= '<td>' . $head_group['total_vp'] . '%</td>';
        $html .= '<td>' . $head_group['total_cs'] . '</td>';
        foreach ($days_reverse as $d) {
            $cnt = $head_daily[$d] ?? ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
            $html .= '<td>' . $cnt['total'] . '</td>';
            $html .= '<td>' . $cnt['keyv'] . '</td>';
            $html .= '<td>' . $cnt['kas'] . '</td>';
            $html .= '<td>' . $cnt['target'] . '</td>';
        }
        $html .= '</tr>';
    }
    // Итог по территории
    $html .= '<tr class="totals-row">';
    $html .= '<td>ИТОГО по ' . htmlspecialchars($terr['name']) . '</td><td></td>';
    $html .= '<td>' . $terr['total_plan'] . '</td>';
    $html .= '<td>' . $terr['total_fact'] . '</td>';
    $html .= '<td>' . $terr['total_rr'] . '</td>';
    $html .= '<td>' . $terr['total_vp'] . '%</td>';
    $html .= '<td>' . $terr['total_cs'] . '</td>';
    foreach ($days_reverse as $d) {
        $cnt = $daily[$d] ?? ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
        $html .= '<td>' . $cnt['total'] . '</td>';
        $html .= '<td>' . $cnt['keyv'] . '</td>';
        $html .= '<td>' . $cnt['kas'] . '</td>';
        $html .= '<td>' . $cnt['target'] . '</td>';
    }
    $html .= '</tr>';
}
// Гранд-итог
$html .= '<tr class="grand-totals">';
$html .= '<td>ВСЕГО</td><td></td>';
$html .= '<td>' . $grand_plan . '</td>';
$html .= '<td>' . $grand_fact . '</td>';
$html .= '<td>' . $grand_rr . '</td>';
$html .= '<td>' . $grand_vp . '%</td>';
$html .= '<td>' . $grand_cs . '</td>';
foreach ($days_reverse as $d) {
    $cnt = $grand_daily[$d] ?? ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
    $html .= '<td>' . $cnt['total'] . '</td>';
    $html .= '<td>' . $cnt['keyv'] . '</td>';
    $html .= '<td>' . $cnt['kas'] . '</td>';
    $html .= '<td>' . $cnt['target'] . '</td>';
}
$html .= '</tr></tbody></table>';

// Детальные таблицы (без комментариев)
foreach ($structure as $terr_id => $terr) {
    $html .= '<h3 style="font-size:9px;margin:4px 0;">🏢 ' . htmlspecialchars($terr['name']) . ' (План: ' . $terr['total_plan'] . ', Факт: ' . $terr['total_fact'] . ', RR: ' . $terr['total_rr'] . ')</h3>';
    $html .= '<table>';
    $html .= '<thead><tr><th>Руководитель</th><th>Менеджер</th><th>Стаж</th><th>RR</th><th>ВП</th><th>ЦС</th>';
    foreach ($days_reverse as $d) {
        $html .= '<th colspan="4">' . $d . '</th>';
    }
    $html .= '<th>План</th><th>Факт</th></tr>';
    $html .= '<tr><th></th><th></th><th></th><th></th><th></th><th></th>';
    foreach ($days_reverse as $d) {
        $html .= '<th>ИНН</th><th>Кл</th><th>Кс</th><th>ЦС</th>';
    }
    $html .= '<th></th><th></th></tr></thead><tbody>';

    foreach ($terr['heads'] as $head_name => $head_group) {
        $managers_list = $head_group['managers'] ?? [];
        $html .= '<tr class="head-row"><td>' . htmlspecialchars($head_name) . '</td><td colspan="' . (6 + 4 * count($display_days) + 2 - 1) . '"></td></tr>';

        // Итог по руководителю
        $head_daily = array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);
        foreach ($managers_list as $m) {
            $t = $m['tabel_key'];
            if (empty($t)) continue;
            foreach ($display_days as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                $head_daily[$d]['total'] += $cnt['total'];
                $head_daily[$d]['keyv'] += $cnt['keyv'];
                $head_daily[$d]['kas']  += $cnt['kas'];
                $head_daily[$d]['target'] += $cnt['target'];
            }
        }
        $html .= '<tr style="font-weight:bold;background:#fff8e1;">';
        $html .= '<td colspan="2">ИТОГО по ' . htmlspecialchars($head_name) . '</td>';
        $html .= '<td></td>';
        $html .= '<td>' . $head_group['total_rr'] . '</td>';
        $html .= '<td>' . $head_group['total_vp'] . '%</td>';
        $html .= '<td>' . $head_group['total_cs'] . '</td>';
        foreach ($days_reverse as $d) {
            $cnt = $head_daily[$d] ?? ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
            $html .= '<td>' . $cnt['total'] . '</td>';
            $html .= '<td>' . $cnt['keyv'] . '</td>';
            $html .= '<td>' . $cnt['kas'] . '</td>';
            $html .= '<td>' . $cnt['target'] . '</td>';
        }
        $html .= '<td>' . $head_group['total_plan'] . '</td>';
        $html .= '<td>' . $head_group['total_fact'] . '</td>';
        $html .= '</tr>';

        foreach ($managers_list as $m) {
            $t = $m['tabel_key'];
            if (empty($t)) continue;
            $plan = (int) ($m['plan'] ?? 0);
            $fact = (int) ($m['fact'] ?? 0);
            $rr   = (int) ($m['rr'] ?? 0);
            $vp   = (int) ($m['vp'] ?? 0);
            $cs   = (int) ($m['target'] ?? 0);
            $staz = $m['staz'] ?? '000/00';

            $html .= '<tr>';
            $html .= '<td></td>';
            $html .= '<td class="name-col">' . htmlspecialchars($m['full_name'] ?? '') . '</td>';
            $html .= '<td>' . $staz . '</td>';
            $html .= '<td>' . $rr . '</td>';
            $html .= '<td>' . $vp . '%</td>';
            $html .= '<td>' . $cs . '</td>';
            foreach ($days_reverse as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $absent = isset($absences[$t][$date_str]) ? true : false;
                $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                if ($absent) {
                    $html .= '<td class="absence-mark">Н</td><td class="absence-mark">Н</td><td class="absence-mark">Н</td><td class="absence-mark">Н</td>';
                } else {
                    // Добавляем цвета для ИНН (по total)
                    $total = $cnt['total'];
                    $colorClass = '';
                    if ($total <= 1) $colorClass = 'red';
                    elseif ($total <= 2) $colorClass = 'yellow';
                    else $colorClass = 'green';
                    $html .= '<td class="' . $colorClass . '">' . $cnt['total'] . '</td>';
                    $html .= '<td>' . $cnt['keyv'] . '</td>';
                    $html .= '<td>' . $cnt['kas'] . '</td>';
                    $html .= '<td>' . $cnt['target'] . '</td>';
                }
            }
            $html .= '<td>' . $plan . '</td>';
            $html .= '<td>' . $fact . '</td>';
            $html .= '</tr>';
        }
    }
    // Итог по территории
    $daily = $terr['daily_totals'] ?? array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);
    $html .= '<tr class="totals-row">';
    $html .= '<td colspan="2">ИТОГО по ' . htmlspecialchars($terr['name']) . '</td>';
    $html .= '<td></td>';
    $html .= '<td>' . $terr['total_rr'] . '</td>';
    $html .= '<td>' . $terr['total_vp'] . '%</td>';
    $html .= '<td>' . $terr['total_cs'] . '</td>';
    foreach ($days_reverse as $d) {
        $cnt = $daily[$d] ?? ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
        $html .= '<td>' . $cnt['total'] . '</td>';
        $html .= '<td>' . $cnt['keyv'] . '</td>';
        $html .= '<td>' . $cnt['kas'] . '</td>';
        $html .= '<td>' . $cnt['target'] . '</td>';
    }
    $html .= '<td>' . $terr['total_plan'] . '</td>';
    $html .= '<td>' . $terr['total_fact'] . '</td>';
    $html .= '</tr></tbody></table>';
}
$html .= '</body></html>';

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("terman_report_$year-$month.pdf", array("Attachment" => 0));
exit;