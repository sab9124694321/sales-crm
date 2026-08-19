<?php
require_once 'db.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['head','territory_head','admin','terman'])) {
    die('Доступ запрещён');
}

require 'vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

require_once 'report_data.php';

$year  = (int) ($_GET['year'] ?? date('Y'));
$month = (int) ($_GET['month'] ?? date('m'));
$territory_filter = (int) ($_GET['territory'] ?? 0);

$data = getReportData($year, $month, $territory_filter);
extract($data);

$html = '<html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #000; padding: 2px 3px; text-align: center; }
    th { background: #f0f0f0; }
    .page-break { page-break-before: always; }
    .header { font-size: 14px; font-weight: bold; margin: 10px 0; }
    .head-row td { background: #f3e5f5; font-weight: 600; text-align: left; padding-left: 8px; }
</style></head><body>';

$first = true;
foreach ($structure as $terr) {
    if (!$first) $html .= '<div class="page-break"></div>';
    $first = false;
    $html .= '<div class="header">' . htmlspecialchars($terr['name']) . '</div>';
    $html .= '<table>';
    $html .= '<tr><th>ФИО Руководителя</th><th>ФИО менеджера</th><th>Стаж</th><th>RR,%</th>';
    foreach ($days_reverse as $d) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $weekday = $weekdays_ru[date('N', strtotime($date_str)) - 1];
        $html .= '<th colspan="3">' . $d . '<br><small>' . $weekday . '</small></th>';
    }
    $html .= '<th>План</th><th>Факт</th><th>ВП,%</th></tr>';

    foreach ($terr['heads'] as $head_name => $head_group) {
        $total_cols = 7 + 3 * count($days_reverse);
        $html .= '<tr class="head-row"><td colspan="' . $total_cols . '">' . htmlspecialchars($head_name) . ' 💬</td></tr>';

        foreach ($head_group['managers'] as $m) {
            $html .= '<tr>';
            $html .= '<td></td>';
            $html .= '<td>' . htmlspecialchars($m['full_name'] ?? '') . ' ✏️</td>';
            $html .= '<td>' . ($m['staz'] ?? '') . '</td>';
            $html .= '<td>' . (int) ($m['rr'] ?? 0) . '</td>';
            foreach ($days_reverse as $d) {
                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $cnt = isset($sales[$m['tabel_key']][$date_str]) ? $sales[$m['tabel_key']][$date_str] : ['mass'=>0, 'keyv'=>0, 'kas'=>0];
                $html .= '<td>' . (int) $cnt['mass'] . '</td><td>' . (int) $cnt['keyv'] . '</td><td>' . (int) $cnt['kas'] . '</td>';
            }
            $html .= '<td>' . (int) ($m['plan'] ?? 0) . '</td>';
            $html .= '<td>' . (int) ($m['fact'] ?? 0) . '</td>';
            $html .= '<td>' . (int) ($m['rr'] ?? 0) . '</td>';
            $html .= '</tr>';
        }

        $html .= '<tr style="font-weight:bold;background:#fff8e1;">';
        $html .= '<td colspan="3">ИТОГО по ' . htmlspecialchars($head_name) . '</td>';
        $html .= '<td>' . (int) ($head_group['total_rr'] ?? 0) . '</td>';
        foreach ($days_reverse as $d) {
            $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $day_total = ['mass'=>0, 'keyv'=>0, 'kas'=>0];
            foreach ($head_group['managers'] as $mgr) {
                if (isset($sales[$mgr['tabel_key']][$date_str])) {
                    $c = $sales[$mgr['tabel_key']][$date_str];
                    $day_total['mass'] += $c['mass'];
                    $day_total['keyv'] += $c['keyv'];
                    $day_total['kas']  += $c['kas'];
                }
            }
            $html .= '<td>' . $day_total['mass'] . '</td><td>' . $day_total['keyv'] . '</td><td>' . $day_total['kas'] . '</td>';
        }
        $html .= '<td>' . (int) ($head_group['total_plan'] ?? 0) . '</td>';
        $html .= '<td>' . (int) ($head_group['total_fact'] ?? 0) . '</td>';
        $html .= '<td>' . (int) ($head_group['total_rr'] ?? 0) . '</td>';
        $html .= '</tr>';
    }

    $html .= '<tr style="font-weight:bold;background:#ffecb3;">';
    $html .= '<td colspan="3">ИТОГО по ' . htmlspecialchars($terr['name']) . '</td>';
    $html .= '<td>' . (int) ($terr['total_rr'] ?? 0) . '</td>';
    foreach ($days_reverse as $d) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $day_total = ['mass'=>0, 'keyv'=>0, 'kas'=>0];
        foreach ($terr['heads'] as $hgrp) {
            foreach ($hgrp['managers'] as $mgr) {
                if (isset($sales[$mgr['tabel_key']][$date_str])) {
                    $c = $sales[$mgr['tabel_key']][$date_str];
                    $day_total['mass'] += $c['mass'];
                    $day_total['keyv'] += $c['keyv'];
                    $day_total['kas']  += $c['kas'];
                }
            }
        }
        $html .= '<td>' . $day_total['mass'] . '</td><td>' . $day_total['keyv'] . '</td><td>' . $day_total['kas'] . '</td>';
    }
    $html .= '<td>' . (int) ($terr['total_plan'] ?? 0) . '</td>';
    $html .= '<td>' . (int) ($terr['total_fact'] ?? 0) . '</td>';
    $html .= '<td>' . (int) ($terr['total_rr'] ?? 0) . '</td>';
    $html .= '</tr>';

    $html .= '</table>';
}

$html .= '</body></html>';

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream("terman_report_$year-$month.pdf", array("Attachment" => 0));
exit;