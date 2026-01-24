<?php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'events' => [
        ['event_date' => '2026-02-16', 'title' => 'Caloocan City Day'],
        ['event_date' => '2026-02-17', 'title' => 'Chinese New Year'],
        ['event_date' => '2026-03-20', 'title' => 'Eid al-Fitr'],
        ['event_date' => '2026-04-02', 'title' => 'Maundy Thursday'],
        ['event_date' => '2026-04-03', 'title' => 'Good Friday'],
        ['event_date' => '2026-04-04', 'title' => 'Holy Saturday'],
        ['event_date' => '2026-04-09', 'title' => 'Day of Valor']
    ]
]);
?>