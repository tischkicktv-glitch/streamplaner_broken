<?php
/**
 * twitch_auth.php
 * Übernimmt den OAuth-Handshake für die 7 Twitch-Kanäle.
 */

try {
    $db = new PDO('sqlite:kicker.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Datenbank-Fehler: " . $e->getMessage());
}

// DIESE URL MUSS EXAKT MIT DER TWITCH-KONSOLE ÜBEREINSTIMMEN
$redirect_uri = "https://nukular.wtf/twitch_auth.php";

// --- SCHRITT 1: START DES LOGINS ---
if (isset($_GET['login']) && isset($_GET['channel_id'])) {
    $chan_id = $_GET['channel_id'];
    
    $stmt = $db->prepare("SELECT client_id FROM channels WHERE id = ?");
    $stmt->execute([$chan_id]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res || empty($res['client_id'])) {
        die("❌ Fehler: Keine Client-ID für diesen Kanal gefunden. Bitte erst im Admin-Panel eintragen und speichern.");
    }

    // Wir entfernen unsichtbare Leerzeichen
    $client_id = trim($res['client_id']);

    // Twitch-Login URL zusammenbauen
    $url = "https://id.twitch.tv/oauth2/authorize" .
           "?client_id=" . $client_id .
           "&redirect_uri=" . urlencode($redirect_uri) .
           "&response_type=code" .
           "&scope=channel:manage:broadcast" . 
           "&state=" . $chan_id; // Wir geben die ID mit, um später zu wissen, welcher Kanal das ist

    header("Location: $url");
    exit;
}

// --- SCHRITT 2: RÜCKKEHR VON TWITCH ---
if (isset($_GET['code'])) {
    $chan_id = $_GET['state']; // Hier steckt unsere Kanal-ID drin
    
    // Client-Daten für diesen Kanal laden
    $stmt = $db->prepare("SELECT client_id, client_secret FROM channels WHERE id = ?");
    $stmt->execute([$chan_id]);
    $chan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chan) {
        die("❌ Fehler: Kanal-Daten konnten nicht geladen werden.");
    }

    // Den Code gegen ein Refresh-Token tauschen
    $ch = curl_init("https://id.twitch.tv/oauth2/token");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id'     => trim($chan['client_id']),
        'client_secret' => trim($chan['client_secret']),
        'code'          => $_GET['code'],
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => $redirect_uri
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $raw_response = curl_exec($ch);
    $response = json_decode($raw_response, true);
    curl_close($ch);

    if (isset($response['refresh_token'])) {
        // Erfolg! Refresh-Token dauerhaft in der Datenbank speichern
        $upd = $db->prepare("UPDATE channels SET refresh_token = ? WHERE id = ?");
        $upd->execute([$response['refresh_token'], $chan_id]);
        
        echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'>";
        echo "<h1 style='color:green;'>✅ Verbindung erfolgreich!</h1>";
        echo "<p>Das Refresh-Token für den Kanal wurde sicher gespeichert.</p>";
        echo "<a href='admin.php' style='display:inline-block; padding:10px 20px; background:#007bff; color:white; text-decoration:none; border-radius:5px;'>Zurück zum Admin-Panel</a>";
        echo "</div>";
    } else {
        // Fehler-Anzeige
        echo "<div style='font-family:sans-serif; padding:20px; border:1px solid red; background:#fff0f0;'>";
        echo "<h1 style='color:red;'>❌ Twitch-Fehler</h1>";
        echo "<p>Twitch hat die Anfrage abgelehnt. Mögliche Gründe: Falsches Client-Secret oder abgelaufener Code.</p>";
        echo "<strong>Antwort von Twitch:</strong><pre>" . htmlspecialchars($raw_response) . "</pre>";
        echo "<br><a href='admin.php'>Zurück zum Admin-Panel</a>";
        echo "</div>";
    }
    exit;
}

// --- FALLBACK ---
if (isset($_GET['error'])) {
    echo "<h1>OAuth Fehler</h1>";
    echo "Grund: " . htmlspecialchars($_GET['error_description']);
    exit;
}

echo "Keine Aktion definiert.";
?>
