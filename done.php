<?php
date_default_timezone_set('Europe/Berlin');
$db_file = __DIR__ . '/kicker.db';
$log_file = __DIR__ . '/logs.txt';

// Nginx übergibt das Ziel (z.B. tischkicktv oder tischkicktv_6)
$streamingziel = trim((string)($_GET['token'] ?? ''));
if ($streamingziel === '$name' || $streamingziel === '$app') {
    $streamingziel = '';
}
if ($streamingziel === '') {
    $streamingziel = trim((string)($_POST['app'] ?? 'unknown'));
}

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Extraktion von Basis-Pfad und Index
    $parts = explode('_', strtolower($streamingziel));
    $base_path = $parts[0];
    
    // WICHTIG: Wenn kein Index mitgeliefert wird, ist $currentIndex NULL
    $currentIndex = isset($parts[1]) ? (string)$parts[1] : null;
    $requestedIndex = ($currentIndex === null) ? '0' : $currentIndex;

    $stmt = $db->prepare("
        SELECT b.id as bid, c.id as cid, c.name as chan_name
        FROM teams t
        JOIN bookings b ON t.id = b.team_id
        JOIN channels c ON b.channel_id = c.id
        WHERE LOWER(t.stream_path) = ?
        AND (b.public_targets = ? OR b.public_targets LIKE ? OR b.public_targets LIKE ? OR b.public_targets LIKE ?)
        AND b.is_live = 1
        ORDER BY b.start_time DESC LIMIT 1
    ");
    $stmt->execute([
        $base_path,
        $requestedIndex,
        $requestedIndex . ',%',
        '%,' . $requestedIndex,
        '%,' . $requestedIndex . ',%'
    ]);

    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($res) {
        $chan_name = $res['chan_name'];
        
        // Status zurücksetzen
        $db->prepare("UPDATE bookings SET is_live = 0 WHERE id = ?")->execute([$res['bid']]);
        $db->prepare("UPDATE channels SET is_live = 0 WHERE id = ?")->execute([$res['cid']]);
        
        // Log schreiben
        $time = date('H:i:s');
        file_put_contents($log_file, "$time | STOP | $chan_name | Stream auf Ziel '$streamingziel' beendet.\n", FILE_APPEND);

        // Signal an alle Frontends
        file_put_contents(__DIR__ . '/last_change.txt', time());
    } else {
        // Fallback-Log
        $time = date('H:i:s');
        file_put_contents($log_file, "$time | INFO | Stream-Ende für '$streamingziel' empfangen, aber keine aktive Buchung gefunden.\n", FILE_APPEND);
    }

} catch (Exception $e) {
    $time = date('H:i:s');
    file_put_contents($log_file, "$time | ERROR | Done.php: " . $e->getMessage() . "\n", FILE_APPEND);
}
