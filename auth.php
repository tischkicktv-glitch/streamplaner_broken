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
// Versuche erst den Token aus der URL (?token=$name)
$token_target = $_GET['token'] ?? '';

// FALLBACK: Wenn kein Token da ist (OBS Feld leer), nimm den App-Namen aus dem POST
if (empty($token_target)) {
    $token_target = $_POST['app'] ?? '';
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
    
    // Aktive Buchung suchen
    // Wir suchen eine Buchung für dieses Team, die JETZT gültig ist
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
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        writeLog("FAIL | Keine Buchung für " . $team['name'] . " aktuell aktiv ($token_target)");
        header("HTTP/1.1 403 Forbidden");
        exit;
    }

    // Prüfen, ob wir auf Twitch (Public) oder Intern streamen
    $public_targets = explode(',', $booking['public_targets'] ?? '');
    
    // Wenn kein Index da ist, nehmen wir standardmäßig an, es ist das Hauptziel
    $is_public = false;
    if ($currentIndex === null) {
        // Logik von gestern: Ohne Index schauen wir, ob überhaupt ein Ziel public ist
        if ($booking['internal_only'] == 0) $is_public = true;
    } else {
        // Mit Index prüfen wir gegen die Liste (z.B. "1,6")
        if (in_array((string)$currentIndex, $public_targets)) $is_public = true;
    }

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
