<?php
require __DIR__ . '/db.php';
$db = getContactsDb();
$rows = $db->query("SELECT * FROM contacts ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$filename = 'contacts_' . date('Y-m-d_H-i') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");

fputcsv($output, [
    'ИНН','Компания','Статус','Руководитель','ОКВЭД','Род деятельности',
    'Заметка для менеджера','Адрес','Телефоны','Email','Сайты','Источники','Дата',
]);

foreach ($rows as $r) {
    fputcsv($output, [
        $r['inn'], $r['company_name'], $r['status'], $r['director_name'],
        $r['okved_main'], $r['okved_description'], $r['activity_note'],
        $r['address'], $r['phones'], $r['emails'], $r['websites'],
        $r['sources'], $r['created_at'],
    ]);
}
fclose($output);
exit;
