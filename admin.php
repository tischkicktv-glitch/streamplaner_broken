<?php 
session_start();
date_default_timezone_set('Europe/Berlin'); 

$admin_password = "HuschiPuschi1!"; 

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}

if (isset($_POST['admin_login'])) {
    if ($_POST['pass'] === $admin_password) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: admin.php");
        exit;
    } else {
        $login_error = "Passwort inkorrekt!";
    }
}

if (!isset($_SESSION['admin_logged_in'])): ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <title>TischKickTV Admin Login</title>
        <style>
            body { font-family: 'Segoe UI', sans-serif; background: #121212; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; color: white; }
            .login-card { background: #1e1e1e; padding: 40px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.6); width: 100%; max-width: 350px; text-align: center; border: 1px solid #333; }
            .logo-main { width: 180px; margin-bottom: 25px; }
            input { width: 100%; padding: 14px; margin: 20px 0; border: 1px solid #333; border-radius: 8px; box-sizing: border-box; font-size: 1rem; text-align: center; background: #000; color: #fff; outline: none; transition: border 0.3s; }
            input:focus { border-color: #1b5e20; }
            .btn-green { background: #1b5e20; color: white; border: none; padding: 14px; width: 100%; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1rem; transition: background 0.3s; }
            .btn-green:hover { background: #144617; }
            .error { color: #ff4d4d; margin-top: 15px; font-size: 0.9rem; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <img src="Avatar_TischKick_Wort-Bild_neu.png" alt="TischKickTV Logo" class="logo-main">
            <h2 style="margin:0; font-size: 1.2rem; letter-spacing: 1px;">MASTER ADMIN</h2>
            <form method="POST">
                <input type="password" name="pass" placeholder="Admin Passwort" autofocus required>
                <button type="submit" name="admin_login" class="btn-green">System entsperren</button>
            </form>
            <?php if(isset($login_error)) echo "<div class='error'>$login_error</div>"; ?>
        </div>
    </body>
    </html>
<?php exit; endif; 

$feedback_msg = "";
header("Cache-Control: no-cache, no-store, must-revalidate"); 

try { 
    $db = new PDO('sqlite:kicker.db'); 
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
} catch (Exception $e) { die("Datenbank-Fehler"); } 

function handleLogoUpload($file, $team_name) {
    if (empty($file['name'])) return null;
    $target_dir = "logos/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $file_name = time() . "_" . preg_replace("/[^a-zA-Z0-9]/", "", $team_name) . "." . $file_ext;
    $target_file = $target_dir . $file_name;
    if (move_uploaded_file($file['tmp_name'], $target_file)) return $target_file;
    return null;
}

function updateNginxSendeziele($db) { 
    $stmt = $db->query("SELECT name, stream_path FROM teams ORDER BY name ASC"); 
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC); 
    $content = "# Automatisch generiert am " . date('Y-m-d H:i:s') . "\n\n"; 
    foreach ($teams as $t) { 
       $path = strtolower(trim($t['stream_path'])); 
       if (empty($path)) continue; 
       $content .= "application " . $path . " {\n";
       $content .= "    live on;\n";
       $content .= "    notify_relay_redirect on;\n";       
//       $content .= "    on_publish http://127.0.0.1/auth.php?token=\$name;\n"; 
       $content .= "    on_publish http://127.0.0.1/auth.php;\n";
       $content .= "    on_publish_done http://127.0.0.1/done.php?token=\$name;\n";
       $content .= "}\n\n";
    }
    @file_put_contents('/etc/nginx/sendeziele.conf', $content);
    @exec('sudo /usr/sbin/nginx -s reload'); 
} 

if (isset($_GET['ajax_update_channel']) && isset($_GET['booking_id']) && isset($_GET['new_channel_id'])) {
    try {
        $stmt = $db->prepare("UPDATE bookings SET channel_id = ? WHERE id = ?");
        $stmt->execute([$_GET['new_channel_id'], $_GET['booking_id']]);
        echo "SUCCESS";
    } catch (Exception $e) { echo "ERROR"; }
    exit;
}

if (isset($_GET['toggle_internal'])) {
    $db->prepare("UPDATE bookings SET internal_only = 1 - internal_only WHERE id = ?")->execute([$_GET['toggle_internal']]);
    $_SESSION['fb'] = ["Status geändert!", "success"]; header("Location: admin.php"); exit;
}
if (isset($_GET['toggle_team_visibility'])) {
    $db->prepare("UPDATE teams SET is_visible = 1 - is_visible WHERE id = ?")->execute([$_GET['toggle_team_visibility']]);
    $_SESSION['fb'] = ["Sichtbarkeit aktualisiert!", "success"]; header("Location: admin.php"); exit;
}
if (isset($_POST['add_team'])) { 
    $logo = handleLogoUpload($_FILES['team_logo'], $_POST['team_name']);
    $db->prepare("INSERT INTO teams (name, token, stream_path, needs_auth, is_visible, logo_url) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$_POST['team_name'], $_POST['team_token'], $_POST['team_rtmp'], 1, 1, $logo]); 
    updateNginxSendeziele($db); 
    $_SESSION['fb'] = ["Verein '{$_POST['team_name']}' hinzugefügt!", "success"]; header("Location: admin.php"); exit; 
} 
if (isset($_POST['update_team_data'])) {
    $db->prepare("UPDATE teams SET token = ?, stream_path = ? WHERE id = ?")
       ->execute([$_POST['edit_token'], $_POST['edit_rtmp'], $_POST['team_id']]);
    updateNginxSendeziele($db);
    $_SESSION['fb'] = ["Vereinsdaten aktualisiert!", "success"];
    header("Location: admin.php"); exit;
}
if (isset($_POST['update_logo'])) {
    $new_logo = handleLogoUpload($_FILES['new_logo'], $_POST['team_name']);
    if ($new_logo) {
        $db->prepare("UPDATE teams SET logo_url = ? WHERE id = ?")->execute([$new_logo, $_POST['team_id']]);
        $_SESSION['fb'] = ["Logo wurde aktualisiert!", "success"];
    }
    header("Location: admin.php"); exit;
}
if (isset($_POST['save_settings'])) { 
    foreach ($_POST['chan'] as $id => $data) { 
         $db->prepare("UPDATE channels SET name=?, obs_key=?, twitch_id=?, client_id=?, secret=? WHERE id=?")->execute([$data['name'], trim($data['obs_key']), $data['twitch_id'], $data['client_id'], $data['secret'], $id]); 
    } 
    $_SESSION['fb'] = ["Einstellungen gespeichert!", "success"]; header("Location: admin.php"); exit;
} 
if (isset($_GET['del_booking'])) { 
    $db->prepare("DELETE FROM bookings WHERE id = ?")->execute([$_GET['del_booking']]); 
    $_SESSION['fb'] = ["Buchung entfernt!", "success"]; header("Location: admin.php"); exit; 
} 
if (isset($_GET['del_team'])) { 
    $db->prepare("DELETE FROM teams WHERE id = ?")->execute([$_GET['del_team']]); 
    updateNginxSendeziele($db); 
    $_SESSION['fb'] = ["Verein gelöscht!", "success"]; header("Location: admin.php"); exit; 
}
if (isset($_GET['clear_log'])) { 
    if(file_exists(__DIR__ . '/logs.txt')) file_put_contents(__DIR__ . '/logs.txt', ''); 
    $_SESSION['fb'] = ["Logs wurden geleert!", "success"]; header("Location: admin.php"); exit; 
} 

if(isset($_SESSION['fb'])) { $feedback_msg = $_SESSION['fb'][0]; unset($_SESSION['fb']); }

$today_limit = date('Y-m-d 00:00:00');
$bookings = $db->query("SELECT b.*, t.name as team_name, c.name as chan_name FROM bookings b JOIN teams t ON b.team_id = t.id JOIN channels c ON b.channel_id = c.id WHERE b.end_time >= '$today_limit' ORDER BY b.start_time ASC")->fetchAll(PDO::FETCH_ASSOC); 
$teams = $db->query("SELECT * FROM teams ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); 
$channels = $db->query("SELECT * FROM channels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC); 
$logs = file_exists(__DIR__ . '/logs.txt') ? file_get_contents(__DIR__ . '/logs.txt') : "Keine Logs vorhanden.";
?> 

<!DOCTYPE html> 
<html lang="de"> 
<head> 
    <meta charset="UTF-8"> 
    <title>TischKickTV Master Admin</title> 
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #121212; margin: 0; padding: 20px; color: #e0e0e0; } 
        .card { background: #1e1e1e; border-radius: 12px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.4); max-width: 1300px; margin: 0 auto 25px; border: 1px solid #333; } 
        .header-logo { height: 65px; margin-right: 20px; }
        .flex-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        h2 { font-size: 1.2rem; margin: 0; color: #fff; border-bottom: 1px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; } 
        th { text-align: left; padding: 12px; border-bottom: 2px solid #333; color: #777; font-size: 0.75rem; text-transform: uppercase; }
        td { padding: 15px 12px; border-bottom: 1px solid #2a2a2a; font-size: 0.9rem; } 
        input, select { padding: 10px; border: 1px solid #333; border-radius: 6px; width: 100%; box-sizing: border-box; background: #000; color: #fff; outline: none; } 
        .btn { padding: 10px 18px; border-radius: 8px; border: none; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; font-size: 0.85rem; } 
        .btn-green { background: #1b5e20; color: white; } 
        .btn-blue { background: #1a73e8; color: white; } 
        .btn-red { background: #ff4d4d; color: white; } 
        .btn-gray { background: #333; color: #aaa; }
        .team-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px; margin-top: 20px; }
        .team-card { background: #161616; border: 1px solid #2a2a2a; border-radius: 10px; padding: 15px; position: relative; display: flex; align-items: flex-start; gap: 15px; }
        .logo-box { width: 70px; height: 70px; background: #000; border-radius: 8px; border: 1px solid #333; position: relative; overflow: hidden; flex-shrink: 0; }
        .logo-box img { width: 100%; height: 100%; object-fit: contain; }
        .logo-box label { position: absolute; top:0; left:0; width:100%; height:100%; background: rgba(0,0,0,0.7); color: white; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; opacity: 0; cursor: pointer; transition: 0.2s; }
        .logo-box:hover label { opacity: 1; }
        
        .edit-field { background: transparent; border: none; border-bottom: 1px solid #333; color: #fff; font-size: 0.75rem; padding: 2px 5px; width: 120px; font-family: monospace; transition: border 0.3s; }
        .edit-field:focus { border-bottom-color: #1b5e20; outline: none; background: #000; }
        .edit-field[type="password"]:hover { background: #000; } /* Hover-Effekt für Token wird über Browser-Verhalten gesteuert */

        .log-area { background: #000; color: #00ff41; padding: 20px; border-radius: 10px; font-family: 'Consolas', monospace; height: 200px; overflow-y: auto; font-size: 0.85rem; border: 1px solid #222; white-space: pre-wrap; } 
        details.month-group { background: #181818; border-radius: 8px; margin-bottom: 10px; border: 1px solid #333; }
        summary.month-summary { padding: 12px 20px; color: #1b5e20; font-weight: bold; cursor: pointer; border-left: 4px solid #1b5e20; list-style: none; }
        .badge-live { color: #8fce00; font-weight: bold; animation: glow 1.5s infinite; }
        @keyframes glow { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        #toast { visibility: hidden; min-width: 250px; background-color: #1b5e20; color: #fff; text-align: center; border-radius: 8px; padding: 16px; position: fixed; z-index: 1000; right: 30px; top: 30px; }
        #toast.show { visibility: visible; animation: fadein 0.5s, fadeout 0.5s 2.5s; }
        @keyframes fadein { from {top: 0; opacity: 0;} to {top: 30px; opacity: 1;} }
        @keyframes fadeout { from {top: 30px; opacity: 1;} to {top: 0; opacity: 0;} }
    </style> 
</head> 
<body> 

<div id="toast"><?php echo $feedback_msg; ?></div>

<div class="card flex-header" style="border-bottom: 3px solid #1b5e20;">
    <div style="display: flex; align-items: center;">
        <img src="Avatar_TischKick_Wort-Bild_neu.png" alt="TischKickTV" class="header-logo">
        <div><h1 style="margin:0; font-size: 1.4rem; color: #fff;">MASTER ADMIN PANEL</h1><small style="color: #1b5e20; font-weight: bold; letter-spacing: 1px;">KICKER-STREAMS CONTROL</small></div>
    </div>
    <a href="?logout=1" class="btn btn-red">Logout</a>
</div>

<div class="card">
    <div class="flex-header"><h2 style="border:none; margin:0;">Vereinsverwaltung</h2>
        <form method="POST" enctype="multipart/form-data" style="display:flex; gap:10px;"> 
             <input type="text" name="team_name" placeholder="Name" required style="width:150px;"> 
             <input type="text" name="team_token" placeholder="Login-Token" required style="width:120px;"> 
             <input type="text" name="team_rtmp" placeholder="RTMP-Key" required style="width:120px;"> 
             <label class="btn btn-blue" style="cursor:pointer; font-size:0.7rem;">🖼️ Logo<input type="file" name="team_logo" accept="image/*" style="display:none;"></label>
             <button type="submit" name="add_team" class="btn btn-green">Hinzufügen</button> 
        </form> 
    </div>
    <div class="team-grid">
         <?php foreach($teams as $t): ?> 
         <div class="team-card">
             <div class="logo-box">
                <?php if(!empty($t['logo_url'])): ?><img src="<?php echo $t['logo_url']; ?>"><?php else: ?><div style="height:100%; display:flex; align-items:center; justify-content:center; font-size:0.5rem; color:#444;">LOGO</div><?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <label for="upload-<?php echo $t['id']; ?>">ÄNDERN</label>
                    <input type="file" name="new_logo" id="upload-<?php echo $t['id']; ?>" style="display:none;" onchange="this.form.submit()">
                    <input type="hidden" name="team_id" value="<?php echo $t['id']; ?>"><input type="hidden" name="team_name" value="<?php echo $t['name']; ?>"><input type="hidden" name="update_logo" value="1">
                </form>
             </div>
             <div class="team-info" style="flex-grow:1;">
                 <a href="?del_team=<?php echo $t['id']; ?>" style="position:absolute; top:10px; right:10px; color:#ff4d4d; text-decoration:none; opacity:0.3;" onclick="return confirm('Löschen?')">✖</a>
                 
                 <form method="POST" style="display: flex; flex-direction: column; gap: 4px;">
                    <strong style="font-size: 1rem; color: #fff; margin-bottom: 2px;"><?php echo htmlspecialchars($t['name']); ?></strong>
                    
                    <div style="font-size:0.75rem; color:#888;">Token: 
                        <input type="password" name="edit_token" class="edit-field" value="<?php echo htmlspecialchars($t['token']); ?>" onmouseover="this.type='text'" onmouseout="this.type='password'">
                    </div>
                    
                    <div style="font-size:0.75rem; color:#888;">RTMP: 
                        <input type="text" name="edit_rtmp" class="edit-field" value="<?php echo htmlspecialchars($t['stream_path'] ?? ''); ?>">
                        <button type="submit" name="update_team_data" style="background:none; border:none; cursor:pointer; color:#1b5e20; font-size:0.9rem; padding:0; margin-left:5px;" title="Speichern">💾</button>
                    </div>
                    
                    <input type="hidden" name="team_id" value="<?php echo $t['id']; ?>">
                 </form>

                 <a href="?toggle_team_visibility=<?php echo $t['id']; ?>" style="font-size:0.7rem; text-decoration:none; color:<?php echo $t['is_visible'] ? '#1b5e20':'#ff4d4d'; ?>; margin-top:5px; display:inline-block;">
                    <?php echo $t['is_visible'] ? '👁️ Öffentlich' : '🚫 Versteckt'; ?>
                 </a>
             </div>
         </div> 
         <?php endforeach; ?> 
    </div>
</div>

<div class="card"> 
    <div class="flex-header"><h2>System Logs (Echtzeit)</h2><a href="?clear_log" class="btn btn-red" style="font-size:0.7rem;">LOGS LEEREN</a></div>
    <div class="log-area" id="logContainer"><?php echo htmlspecialchars($logs); ?></div> 
</div> 

<div class="card">
    <h2>Buchungen (Monatsübersicht)</h2>
    <?php 
    $grouped = [];
    $monate_de = ["January"=>"Januar","February"=>"Februar","March"=>"März","April"=>"April","May"=>"Mai","June"=>"Juni","July"=>"Juli","August"=>"August","September"=>"September","October"=>"Oktober","November"=>"November","December"=>"Dezember"];
    foreach($bookings as $b) {
        $mKey = date('F Y', strtotime($b['start_time']));
        $grouped[$mKey][] = $b;
    }
    foreach($grouped as $monthTitle => $mBookings): 
        $tParts = explode(' ', $monthTitle);
        $displayTitle = strtoupper(($monate_de[$tParts[0]] ?? $tParts[0]) . " " . $tParts[1]);
    ?>
    <details class="month-group" <?php echo ($monthTitle === date('F Y')) ? 'open' : ''; ?>>
        <summary class="month-summary">▶ <?php echo $displayTitle; ?> (<?php echo count($mBookings); ?>)</summary>
        <div style="padding:15px;">
            <table>
                <thead><tr><th>Zeit</th><th>Kanal</th><th>Verein</th><th>Status</th><th>Modus</th><th>Aktion</th></tr></thead>
                <tbody>
                    <?php foreach($mBookings as $b): $is_finished = (time() > strtotime($b['end_time'])); ?>
                    <tr style="<?php echo ($is_finished && !$b['is_live']) ? 'opacity: 0.4;' : ''; ?>">
                        <td><strong><?php echo date('d.m.', strtotime($b['start_time'])); ?></strong><br><small><?php echo date('H:i', strtotime($b['start_time'])); ?></small></td>
                        <td><select onchange="updateChan(this, <?php echo $b['id']; ?>)"><?php foreach($channels as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $b['channel_id']) ? 'selected' : ''; ?>><?php echo $c['name']; ?></option><?php endforeach; ?></select><span id="res-<?php echo $b['id']; ?>"></span></td>
                        <td><strong><?php echo $b['team_name']; ?></strong></td>
                        <td><?php echo $b['is_live'] ? '<span class="badge-live">● LIVE</span>' : ($is_finished ? 'Beendet' : 'Wartet'); ?></td>
                        <td><a href="?toggle_internal=<?php echo $b['id']; ?>" class="btn <?php echo $b['internal_only'] ? 'btn-gray':'btn-green'; ?>" style="font-size:0.7rem;"><?php echo $b['internal_only'] ? 'TEST':'PUBLIC'; ?></a></td>
                        <td><a href="?del_booking=<?php echo $b['id']; ?>" class="btn btn-red" onclick="return confirm('Löschen?')">X</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
    <?php endforeach; ?>
</div>

<div class="card"><details><summary style="cursor:pointer; color:#1a73e8; font-weight:bold;">⚙️ Twitch API & Kanäle</summary>
<form method="POST" style="margin-top:15px;"><table><thead><tr><th>Name</th><th>OBS Key</th><th>Twitch ID</th><th>Client ID</th><th>Secret</th><th>Auth</th></tr></thead><tbody>
<?php foreach($channels as $c): ?><tr>
<td><input type="text" name="chan[<?php echo $c['id']; ?>][name]" value="<?php echo $c['name']; ?>"></td>
<td><input type="password" name="chan[<?php echo $c['id']; ?>][obs_key]" value="<?php echo $c['obs_key']; ?>"></td>
<td><input type="text" name="chan[<?php echo $c['id']; ?>][twitch_id]" value="<?php echo $c['twitch_id']; ?>"></td>
<td><input type="text" name="chan[<?php echo $c['id']; ?>][client_id]" value="<?php echo $c['client_id']; ?>"></td>
<td><input type="password" name="chan[<?php echo $c['id']; ?>][secret]" value="<?php echo $c['secret']; ?>"></td>
<td><a href="auth.php?id=<?php echo $c['id']; ?>" class="btn btn-blue">🔗</a></td>
</tr><?php endforeach; ?></tbody></table><button type="submit" name="save_settings" class="btn btn-green" style="width:100%; margin-top:10px;">SPEICHERN</button></form></details></div>

<script>
    function updateChan(sel, id) {
        fetch(`admin.php?ajax_update_channel=1&booking_id=${id}&new_channel_id=${sel.value}`)
        .then(r => r.text()).then(t => {
            const s = document.getElementById('res-'+id);
            if(t.trim()==="SUCCESS") {
                s.innerText = "✓"; s.style.color="#1b5e20";
                setTimeout(() => s.innerText = "", 2000);
            }
        });
    }
    window.onload = function() {
        const l = document.getElementById('logContainer'); if(l) l.scrollTop = l.scrollHeight;
        if(document.getElementById("toast").innerText.trim() !== "") {
            var x = document.getElementById("toast"); x.className = "show";
            setTimeout(function(){ x.className = ""; }, 3000);
        }
    };
</script>
</body> 
</html>
