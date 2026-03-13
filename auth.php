<?php
date_default_timezone_set('Europe/Berlin');
$db_file = __DIR__ . '/kicker.db';
$log_file = __DIR__ . '/logs.txt';

function writeLog($msg) {
    global $log_file;
    $time = date('H:i:s');
    file_put_contents($log_file, "$time | $msg\n", FILE_APPEND | LOCK_EX);
    @file_put_contents(__DIR__ . '/last_change.txt', time());
}

// 1. Identifikation (Der entscheidende Teil!)
// Primär aus URL-Token lesen, dann robust auf RTMP-Notify-Felder fallen.
$token_target = trim((string)($_GET['token'] ?? ''));

// Manche Nginx-Setups liefern Platzhalter wie "$name" unverändert.
if ($token_target === '$name' || $token_target === '$app') {
    $token_target = '';
}

if (empty($token_target)) {
    $token_target = trim((string)($_POST['app'] ?? ''));
}
if (empty($token_target)) {
    $token_target = trim((string)($_POST['name'] ?? ''));
}

if (empty($token_target)) {
    writeLog("FAIL | Weder Token noch App-Name empfangen.");
    header("HTTP/1.1 403 Forbidden");
    exit;
}

try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Gestern hatten wir die Logik mit den Suffixen (_1, _6 etc.)
    // Wir trennen den Basispfad (z.B. tischkicktv) vom Index (_6)
    $parts = explode('_', strtolower($token_target));
    $base_path = $parts[0];
    $currentIndex = isset($parts[1]) ? $parts[1] : null;
    $requestedIndex = ($currentIndex !== null && $currentIndex !== '') ? (string)$currentIndex : null;

    // Team anhand des stream_path finden
    $stmt = $db->prepare("SELECT id, name FROM teams WHERE LOWER(stream_path) = ? LIMIT 1");
    $stmt->execute([$base_path]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$team) {
        writeLog("FAIL | Team fuer Pfad '$base_path' nicht gefunden (Input: $token_target)");
        header("HTTP/1.1 403 Forbidden");
        exit;
    }

    $now = date('Y-m-d H:i:s');
    
    // Aktive Buchung suchen (zeitlich aktiv). Mit Suffix wird zielgenau nach public_targets gefiltert.
    if ($requestedIndex !== null) {
        $stmt = $db->prepare("
            SELECT b.*, c.name as chan_name, c.obs_key, c.twitch_id, c.client_id, c.secret as client_secret, c.refresh_token
            FROM bookings b
            JOIN channels c ON b.channel_id = c.id
            WHERE b.team_id = ?
            AND b.start_time <= ?
            AND b.end_time >= ?
            AND (b.public_targets = ? OR b.public_targets LIKE ? OR b.public_targets LIKE ? OR b.public_targets LIKE ?)
            ORDER BY b.start_time ASC LIMIT 1
        ");
        $stmt->execute([
            $team['id'],
            $now,
            $now,
            $requestedIndex,
            $requestedIndex . ',%',
            '%,' . $requestedIndex,
            '%,' . $requestedIndex . ',%'
        ]);
    } else {
        $stmt = $db->prepare("
            SELECT b.*, c.name as chan_name, c.obs_key, c.twitch_id, c.client_id, c.secret as client_secret, c.refresh_token
            FROM bookings b
            JOIN channels c ON b.channel_id = c.id
            WHERE b.team_id = ?
            AND b.start_time <= ?
            AND b.end_time >= ?
            ORDER BY b.start_time ASC LIMIT 1
        ");
        $stmt->execute([$team['id'], $now, $now]);
    }

    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $index_label = ($requestedIndex === null) ? 'ANY' : $requestedIndex;
        writeLog("FAIL | Keine passende Buchung für " . $team['name'] . " auf Zielindex $index_label aktiv ($token_target)");
        header("HTTP/1.1 403 Forbidden");
        exit;
    }

    // Twitch nur bei nicht-intern.
    $is_public = ($booking['internal_only'] == 0);

    if ($is_public && !empty($booking['obs_key'])) {
        // LIVE ZU TWITCH
        $twitch_url = "rtmp://euc10.contribute.live-video.net/app/" . trim($booking['obs_key']);
        writeLog("OK | " . $booking['chan_name'] . " | " . $team['name'] . " ($token_target -> TWITCH)");
        
        $db->prepare("UPDATE bookings SET is_live = 1 WHERE id = ?")->execute([$booking['id']]);
        header("HTTP/1.1 302 Moved Temporarily");
        header("Location: " . $twitch_url);
    } else {
        // INTERN
        writeLog("OK | " . $booking['chan_name'] . " | " . $team['name'] . " ($token_target -> INTERN)");
        $db->prepare("UPDATE bookings SET is_live = 1 WHERE id = ?")->execute([$booking['id']]);
        header("HTTP/1.1 200 OK");
    }

} catch (Exception $e) {
    writeLog("ERROR | " . $e->getMessage());
    header("HTTP/1.1 500 Internal Error");
}
