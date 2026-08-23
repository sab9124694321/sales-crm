<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { die('Unauthorized'); }
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['head', 'territory_head', 'admin', 'terman'])) die('Доступ запрещён.');

// ── Функция получения показателей за день ──────────────
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

// ── Функция получения комментария ──────────────────────
function getComment($pdo, $user_tabel, $date, $target_role = 'head') {
    $stmt = $pdo->prepare("SELECT comment FROM head_comments WHERE head_tabel = ? AND comment_date = ? AND target_role = ?");
    $stmt->execute([$user_tabel, $date, $target_role]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['comment'] ?? '';
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
$today_str = date('Y-m-d');

// ── Подготовка комментариев ──────────────────────────────
$head_comments = [];
$manager_comments = [];
foreach ($structure as $terr) {
    foreach ($terr['heads'] as $head_name => $head_group) {
        $head_tabel = $head_group['head_tabel'] ?? '';
        $head_comments[$head_tabel] = getComment($pdo, $head_tabel, $today_str, 'head');
        foreach ($head_group['managers'] as $m) {
            $t = $m['tabel_key'] ?? '';
            if ($t) {
                $manager_comments[$t] = getComment($pdo, $t, $today_str, 'manager');
            }
        }
    }
}

// ── Формирование Excel ──────────────────────────────────
require_once 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

$row = 1;
$col = 1;

// Вспомогательная функция для установки значения по координатам
function setCell($sheet, $col, $row, $value) {
    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $row, $value);
}

// ── Заголовки ─────────────────────────────────────────────
// Первая строка: основные заголовки
$headers = [
    'ФИО Руководителя',
    'Минипротокол',
    'ФИО менеджера',
    'Стаж (Г.М)',
    'RR',
    'ВП',
    'РП'
];
foreach ($days_reverse as $d) {
    $headers[] = $d;        // под ним будут подзаголовки для 4 колонок
}
// Добавляем План и Факт в конец
$headers[] = 'План';
$headers[] = 'Факт';
foreach ($headers as $idx => $val) {
    setCell($sheet, $idx+1, $row, $val);
}

// Вторая строка: подзаголовки для дней (ИНН, Кл, Кс, РП) и пустые для остальных
$row = 2;
$subHeaders = [];
for ($i = 0; $i < 7; $i++) $subHeaders[] = ''; // для первых 7 колонок пусто
foreach ($days_reverse as $d) {
    $subHeaders[] = 'ИНН';
    $subHeaders[] = 'Кл';
    $subHeaders[] = 'Кс';
    $subHeaders[] = 'РП';
}
$subHeaders[] = ''; // План
$subHeaders[] = ''; // Факт
foreach ($subHeaders as $idx => $val) {
    setCell($sheet, $idx+1, $row, $val);
}

// ── Данные ─────────────────────────────────────────────────
$row = 3;
foreach ($structure as $terr_id => $terr) {
    // Название территории как разделитель (необязательно)
    // Выведем как строку-заголовок с объединением ячеек
    $startCol = 1;
    $endCol = 7 + 4 * count($days_reverse) + 2; // общее кол-во колонок
    $sheet->mergeCells(Coordinate::stringFromColumnIndex($startCol) . $row . ':' . Coordinate::stringFromColumnIndex($endCol) . $row);
    setCell($sheet, $startCol, $row, '🏢 ' . $terr['name']);
    $sheet->getStyle(Coordinate::stringFromColumnIndex($startCol) . $row)->getFont()->setBold(true);
    $row++;

    foreach ($terr['heads'] as $head_name => $head_group) {
        $head_tabel = $head_group['head_tabel'] ?? '';
        $head_comment = $head_comments[$head_tabel] ?? '';
        $head_plan = $head_group['total_plan'];
        $head_fact = $head_group['total_fact'];
        $head_rr   = $head_group['total_rr'];
        $head_vp   = $head_group['total_vp'];
        $head_cs   = $head_group['total_cs'];

        // Строка руководителя
        $col = 1;
        setCell($sheet, $col++, $row, $head_name);
        setCell($sheet, $col++, $row, $head_comment);
        // остальные колонки до дней пропускаем
        for ($i = 3; $i <= 7; $i++) setCell($sheet, $col++, $row, '');
        // Дневные итоги для руководителя
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
        foreach ($days_reverse as $d) {
            $cnt = $head_daily[$d];
            setCell($sheet, $col++, $row, $cnt['total']);
            setCell($sheet, $col++, $row, $cnt['keyv']);
            setCell($sheet, $col++, $row, $cnt['kas']);
            setCell($sheet, $col++, $row, $cnt['target']);
        }
        setCell($sheet, $col++, $row, $head_plan);
        setCell($sheet, $col++, $row, $head_fact);
        $row++;

        // Строка "ИТОГО по руководителю"
        $col = 1;
        setCell($sheet, $col++, $row, 'ИТОГО по ' . $head_name);
        setCell($sheet, $col++, $row, ''); // комментарий пуст
        for ($i = 3; $i <= 7; $i++) setCell($sheet, $col++, $row, '');
        foreach ($days_reverse as $d) {
            $cnt = $head_daily[$d];
            setCell($sheet, $col++, $row, $cnt['total']);
            setCell($sheet, $col++, $row, $cnt['keyv']);
            setCell($sheet, $col++, $row, $cnt['kas']);
            setCell($sheet, $col++, $row, $cnt['target']);
        }
        setCell($sheet, $col++, $row, $head_plan);
        setCell($sheet, $col++, $row, $head_fact);
        $row++;

        // Строки менеджеров
        foreach ($head_group['managers'] as $m) {
            $t = $m['tabel_key'];
            if (empty($t)) continue;
            $plan = (int) ($m['plan'] ?? 0);
            $fact = (int) ($m['fact'] ?? 0);
            $rr   = (int) ($m['rr'] ?? 0);
            $vp   = (int) ($m['vp'] ?? 0);
            $cs   = (int) ($m['target'] ?? 0);
            $staz = $m['staz'] ?? '000/00';
            $manager_comment = $manager_comments[$t] ?? '';

            $col = 1;
            setCell($sheet, $col++, $row, ''); // ФИО руководителя пусто
            setCell($sheet, $col++, $row, $manager_comment);
            setCell($sheet, $col++, $row, $m['full_name'] ?? '');
            setCell($sheet, $col++, $row, $staz);
            setCell($sheet, $col++, $row, $rr);
            setCell($sheet, $col++, $row, $vp . '%');
            setCell($sheet, $col++, $row, $cs);

            foreach ($days_reverse as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $absent = isset($absences[$t][$date_str]) ? true : false;
                $cnt = isset($sales[$t][$date_str]) ? $sales[$t][$date_str] : ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
                if ($absent) {
                    setCell($sheet, $col++, $row, 'Н');
                    setCell($sheet, $col++, $row, 'Н');
                    setCell($sheet, $col++, $row, 'Н');
                    setCell($sheet, $col++, $row, 'Н');
                } else {
                    setCell($sheet, $col++, $row, $cnt['total']);
                    setCell($sheet, $col++, $row, $cnt['keyv']);
                    setCell($sheet, $col++, $row, $cnt['kas']);
                    setCell($sheet, $col++, $row, $cnt['target']);
                }
            }
            setCell($sheet, $col++, $row, $plan);
            setCell($sheet, $col++, $row, $fact);
            $row++;
        }
    }

    // Итог по территории
    $daily = $terr['daily_totals'] ?? array_fill_keys($display_days, ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0]);
    $col = 1;
    setCell($sheet, $col++, $row, 'ИТОГО по ' . $terr['name']);
    setCell($sheet, $col++, $row, '');
    for ($i = 3; $i <= 7; $i++) setCell($sheet, $col++, $row, '');
    foreach ($days_reverse as $d) {
        $cnt = $daily[$d] ?? ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
        setCell($sheet, $col++, $row, $cnt['total']);
        setCell($sheet, $col++, $row, $cnt['keyv']);
        setCell($sheet, $col++, $row, $cnt['kas']);
        setCell($sheet, $col++, $row, $cnt['target']);
    }
    setCell($sheet, $col++, $row, $terr['total_plan']);
    setCell($sheet, $col++, $row, $terr['total_fact']);
    $row++;
}

// Гранд-итог
$col = 1;
setCell($sheet, $col++, $row, 'ВСЕГО');
setCell($sheet, $col++, $row, '');
for ($i = 3; $i <= 7; $i++) setCell($sheet, $col++, $row, '');
foreach ($days_reverse as $d) {
    $cnt = $grand_daily[$d] ?? ['total'=>0, 'keyv'=>0, 'kas'=>0, 'target'=>0];
    setCell($sheet, $col++, $row, $cnt['total']);
    setCell($sheet, $col++, $row, $cnt['keyv']);
    setCell($sheet, $col++, $row, $cnt['kas']);
    setCell($sheet, $col++, $row, $cnt['target']);
}
setCell($sheet, $col++, $row, $grand_plan);
setCell($sheet, $col++, $row, $grand_fact);

// ── Автоширина ─────────────────────────────────────────────
$totalCols = 7 + 4 * count($days_reverse) + 2;
foreach (range(1, $totalCols) as $colIdx) {
    $sheet->getColumnDimensionByColumn($colIdx)->setAutoSize(true);
}

// ── Вывод ──────────────────────────────────────────────────
$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="terman_report_detailed_'.$year.'_'.$month.'.xlsx"');
$writer->save('php://output');
exit;