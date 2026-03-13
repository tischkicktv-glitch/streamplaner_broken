<?php
session_start();
date_default_timezone_set('Europe/Berlin');

try {
    $db = new PDO('sqlite:kicker.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_no_double_start ON bookings (channel_id, start_time)");
} catch (Exception $e) { die("Datenbank-Verbindung fehlgeschlagen."); }

$error_msg = "";
$conflict_html = ""; 

// --- LOGIN LOGIK ---
if (isset($_GET['token'])) { $_POST['login_token'] = $_GET['token']; }
if (isset($_POST['login_token'])) {
    $stmt = $db->prepare("SELECT * FROM teams WHERE token = ?");
    $stmt->execute([trim($_POST['login_token'])]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($team) {
        $_SESSION['team_id'] = $team['id'];
        $_SESSION['team_name'] = $team['name'];
        header("Location: index.php"); exit;
    } else { $login_error = "Ungültiger Token."; }
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

if (!isset($_SESSION['team_id'])): 
?>
    <!DOCTYPE html>
    <html lang="de"><head><meta charset="UTF-8"><title>TischKickTV Login</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #121212; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; color: white; }
        .login-card { background: #1e1e1e; padding: 40px; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.6); width: 100%; max-width: 400px; text-align: center; border: 1px solid #333; }
        .logo-main { width: 180px; margin-bottom: 20px; }
        input { width: 100%; padding: 14px; margin: 20px 0; border: 1px solid #333; border-radius: 8px; box-sizing: border-box; font-size: 1rem; text-align: center; background: #000; color: #fff; outline: none; }
        .btn-green { background: #1b5e20; color: white; border: none; padding: 14px; width: 100%; border-radius: 8px; font-weight: bold; cursor: pointer; }
    </style>
    </head><body><div class="login-card">
    <img src="Avatar_TischKick_Wort-Bild_neu.png" alt="TischKickTV" class="logo-main">
    <form method="POST"><input type="text" name="login_token" placeholder="Vereins-Token" autofocus required><button type="submit" class="btn-green">System betreten</button></form>
    </div></body></html>
<?php exit; endif; 

// --- HAUPT-LOGIK ---
$my_team_id = $_SESSION['team_id'];
$my_team_name = $_SESSION['team_name'];

if (isset($_GET['del'])) {
    $db->prepare("DELETE FROM bookings WHERE id = ? AND team_id = ?")->execute([$_GET['del'], $my_team_id]);
    header("Location: index.php"); exit;
}

$f_date = $_POST['date'] ?? date('Y-m-d');
$f_start = $_POST['start'] ?? '';
$f_end = $_POST['end'] ?? '';
$f_title = $_POST['title'] ?? '';
$f_multi = isset($_POST['multi_table_mode']);
$f_single_chan = $_POST['single_channel'] ?? 1;
$f_sel_chans = $_POST['channels'] ?? [];
$f_multi_titles = $_POST['multi_titles'] ?? [];
$f_target = $_POST['stream_target'] ?? 'twitch';
$f_edit_id = $_POST['edit_id'] ?? '';

if (isset($_POST['add_booking'])) {
    $start_ts = strtotime("$f_date $f_start:00");
    $end_ts   = strtotime("$f_date $f_end:00");
    if ($end_ts <= $start_ts) { $end_ts = strtotime("$f_date $f_end:00 +1 day"); }
    $internal = ($f_target == 'internal' ? 1 : 0);
    $final_channels = $f_multi ? $f_sel_chans : [$f_single_chan];

    if (empty($final_channels)) {
        $error_msg = "❌ Bitte wähle mindestens einen Kanal aus.";
    } else {
        $conflicts = [];
        $all_available_chans = $db->query("SELECT id, name FROM channels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($final_channels as $chan_id) {
            $sql_chk = "SELECT COUNT(*) FROM bookings WHERE channel_id = ? AND (start_time < ? AND end_time > ?)";
            $params_chk = [(int)$chan_id, date('Y-m-d H:i:s', $end_ts), date('Y-m-d H:i:s', $start_ts)];
            if (!empty($f_edit_id)) { $sql_chk .= " AND id != ?"; $params_chk[] = (int)$f_edit_id; }
            $chk = $db->prepare($sql_chk); $chk->execute($params_chk);
            
            if ($chk->fetchColumn() > 0) {
                $cname = $db->query("SELECT name FROM channels WHERE id = ".(int)$chan_id)->fetchColumn();
                
                // Vorschläge finden (Kanäle, die weder belegt sind noch aktuell in der Auswahl des Users liegen)
                $free_alts = [];
                foreach($all_available_chans as $ac) {
                    if(in_array($ac['id'], $final_channels)) continue; // Schon ausgewählt oder belegt
                    
                    $sql_alt = "SELECT COUNT(*) FROM bookings WHERE channel_id = ? AND (start_time < ? AND end_time > ?)";
                    $params_alt = [(int)$ac['id'], date('Y-m-d H:i:s', $end_ts), date('Y-m-d H:i:s', $start_ts)];
                    if (!empty($f_edit_id)) { $sql_alt .= " AND id != ?"; $params_alt[] = (int)$f_edit_id; }
                    $achk = $db->prepare($sql_alt); $achk->execute($params_alt);
                    
                    if ($achk->fetchColumn() == 0) {
                        $free_alts[] = "<button type='button' class='alt-btn' onclick='swapChannel(".$chan_id.", ".$ac['id'].")'>Nutze ".htmlspecialchars($ac['name'])."</button>";
                    }
                }
                $conflicts[] = ['id' => $chan_id, 'name' => $cname, 'alts' => $free_alts];
            }
        }

        if (empty($conflicts)) {
            $db->beginTransaction();
            try {
                if (!empty($f_edit_id)) { $db->prepare("DELETE FROM bookings WHERE id = ? AND team_id = ?")->execute([(int)$f_edit_id, $my_team_id]); }
                foreach ($final_channels as $chan_id) {
                    $current_title = $f_multi ? trim($f_multi_titles[$chan_id] ?? $f_title) : $f_title;
                    if(empty($current_title)) $current_title = "TischKick Live Stream";
                    $final_idx = (int)$chan_id - 1;
                    $ins = $db->prepare("INSERT INTO bookings (team_id, channel_id, start_time, end_time, stream_title, internal_only, public_targets) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$my_team_id, (int)$chan_id, date('Y-m-d H:i:s', $start_ts), date('Y-m-d H:i:s', $end_ts), $current_title, $internal, (string)$final_idx]);
                }
                $db->commit(); header("Location: index.php?success=1"); exit;
            } catch (Exception $e) { $db->rollBack(); $error_msg = "Fehler: " . $e->getMessage(); }
        } else { 
            $error_msg = "❌ Ein oder mehrere Kanäle sind belegt."; 
            foreach($conflicts as $con) {
                $alt_list = !empty($con['alts']) ? implode(" ", $con['alts']) : "<small>Kein Ersatzkanal verfügbar.</small>";
                $conflict_html .= "<div class='conflict-row'>Kanal <strong>" . htmlspecialchars($con['name']) . "</strong> belegt. Alternativen: <div class='alt-box'>" . $alt_list . "</div></div>";
            }
        }
    }
}

$my_bookings = $db->prepare("SELECT b.*, c.name as chan_name FROM bookings b JOIN channels c ON b.channel_id = c.id WHERE b.team_id = ? AND b.end_time >= datetime('now','localtime') ORDER BY b.start_time ASC");
$my_bookings->execute([$my_team_id]);
$channels = $db->query("SELECT * FROM channels ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>TischKickTV Planer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        :root { --accent: #8fce00; --bg: #121212; --card: #1e1e1e; --input: #000; --text: #e0e0e0; }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); padding: 20px; color: var(--text); margin: 0; }
        .card { background: var(--card); border-radius: 12px; padding: 25px; box-shadow: 0 4px 20px rgba(0,0,0,0.4); max-width: 1100px; margin: 0 auto 25px; border: 1px solid #333; }
        .header-flex { display: flex; justify-content: space-between; align-items: center; }
        .header-logo { height: 60px; }
        h1, h2 { margin: 0 0 20px 0; font-weight: 300; }
        h1 { font-size: 1.8rem; color: #fff; }
        h2 { font-size: 1.2rem; color: var(--accent); text-transform: uppercase; letter-spacing: 1px; }
        label { display: block; font-size: 0.75rem; font-weight: 700; margin-bottom: 8px; color: #888; text-transform: uppercase; }
        input, select { width: 100%; padding: 12px; margin-bottom: 20px; border: 1px solid #333; border-radius: 6px; background: var(--input); color: #fff; outline: none; font-size: 1rem; box-sizing: border-box; }
        
        .grid-layout { display: grid; gap: 12px; margin-bottom: 20px; }
        #single-channel-container .grid-layout { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
        #matrix-container .grid-layout { grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); }
        
        .tile { background: #000; border: 1px solid #333; padding: 15px; border-radius: 8px; cursor: pointer; transition: 0.2s; position: relative; }
        .tile:hover { border-color: #555; }
        .tile.selected { border-color: var(--accent); background: rgba(143, 206, 0, 0.05); }
        .tile input[type="radio"], .tile input[type="checkbox"] { position: absolute; opacity: 0; }
        
        .tile-label { display: flex; align-items: center; justify-content: space-between; font-weight: bold; color: #888; }
        .tile.selected .tile-label { color: var(--accent); }
        
        .tile-title-input { margin-top: 12px; display: none; }
        .tile.selected .tile-title-input { display: block; }
        .tile-title-input input { margin-bottom: 0; padding: 10px; font-size: 0.9rem; width: 100%; border-color: #444; }

        .btn-green { background: #1b5e20; color: white; border: none; padding: 18px; width: 100%; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 1rem; text-transform: uppercase; }
        .btn-green:hover { background: #2e7d32; }
        .logout-link { font-size: 0.8rem; color: #ff4d4d; text-decoration: none; border: 1px solid #ff4d4d; padding: 6px 12px; border-radius: 6px; }
        
        .multi-switch { background: #252525; padding: 18px; border-radius: 8px; margin-bottom: 25px; display: flex; align-items: center; cursor: pointer; border: 1px solid #333; }
        .multi-switch input { width: auto; margin-right: 15px; margin-bottom: 0; transform: scale(1.3); }
        
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 0.7rem; color: #666; padding: 12px; border-bottom: 2px solid #333; text-transform: uppercase; }
        td { padding: 14px 12px; border-bottom: 1px solid #2a2a2a; }
        .chan-name { color: var(--accent); font-weight: bold; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: bold; }
        .badge-live { background: #1b5e20; color: #fff; }
        .badge-internal { background: #444; color: #aaa; }
        .btn-action { text-decoration: none; font-size: 0.75rem; font-weight: bold; padding: 8px 14px; border-radius: 4px; cursor: pointer; border: 1px solid transparent; }
        .btn-edit { color: var(--accent); border-color: var(--accent); background: transparent; margin-right: 8px; }
        .btn-storno { color: #ff4d4d; border-color: #ff4d4d; background: transparent; }
        
        .alert-error { background: #3d1010; color: #ff9b9b; border-left: 4px solid #ff4d4d; padding: 15px; border-radius: 4px; margin-bottom: 25px; }
        .conflict-row { background: rgba(0,0,0,0.3); padding: 10px; margin-top: 10px; border-radius: 6px; border: 1px solid #555; }
        .alt-box { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 5px; }
        .alt-btn { background: transparent; color: var(--accent); border: 1px solid var(--accent); padding: 5px 12px; border-radius: 4px; cursor: pointer; font-size: 0.8rem; }
        
        .edit-mode-indicator { background: #333; padding: 10px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid var(--accent); display: none; }
    </style>
</head>
<body>

<div class="card header-flex">
    <div style="display: flex; align-items: center; gap: 20px;">
        <img src="Avatar_TischKick_Wort-Bild_neu.png" alt="Logo" class="header-logo">
        <h1><?php echo htmlspecialchars($my_team_name); ?></h1>
    </div>
    <a href="?logout=1" class="logout-link">Abmelden</a>
</div>

<?php if(!empty($error_msg)): ?>
    <div class="card alert-error">
        <div><strong><?php echo $error_msg; ?></strong></div>
        <?php echo $conflict_html; ?>
    </div>
<?php endif; ?>

<div class="card" id="formAnchor">
    <div id="editIndicator" class="edit-mode-indicator">
        <strong>Modus: Bearbeitung</strong> — <span onclick="cancelEdit()" style="text-decoration: underline; cursor:pointer;">Abbrechen</span>
    </div>
    <h2 id="formTitle">Stream-Termin reservieren</h2>
    <form method="POST" id="bookingForm">
        <input type="hidden" name="edit_id" id="editIdField" value="<?php echo htmlspecialchars($f_edit_id); ?>">
        
        <label>Datum</label>
        <input type="date" name="date" id="field_date" value="<?php echo htmlspecialchars($f_date); ?>" required>
        <div style="display: flex; gap: 20px;">
            <div style="flex: 1;"><label>Startzeit</label><input type="time" name="start" id="field_start" value="<?php echo htmlspecialchars($f_start); ?>" required></div>
            <div style="flex: 1;"><label>Ende (ca.)</label><input type="time" name="end" id="field_end" value="<?php echo htmlspecialchars($f_end); ?>" required></div>
        </div>
        
        <label>Haupt-Titel der Begegnung</label>
        <input type="text" name="title" id="mainTitle" value="<?php echo htmlspecialchars($f_title); ?>" placeholder="z.B. DTFL Bundesliga 2026" oninput="syncTitles()">

        <label class="multi-switch">
            <input type="checkbox" name="multi_table_mode" id="multiToggle" onchange="toggleMode()" <?php echo $f_multi ? 'checked' : ''; ?>>
            <span>Multi-Table-Modus aktivieren</span>
        </label>

        <div id="single-channel-container" style="<?php echo $f_multi ? 'display:none' : 'display:block'; ?>">
            <label>Twitch-Kanal auswählen</label>
            <div class="grid-layout">
                <?php foreach($channels as $c): ?>
                    <label class="tile <?php echo ($f_single_chan == $c['id']) ? 'selected' : ''; ?>" id="label_single_<?php echo $c['id']; ?>">
                        <input type="radio" name="single_channel" value="<?php echo $c['id']; ?>" <?php echo ($f_single_chan == $c['id']) ? 'checked' : ''; ?> onchange="updateSingleSelection(this.value)">
                        <div class="tile-label"><span>📺 <?php echo htmlspecialchars($c['name']); ?></span></div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div id="matrix-container" style="<?php echo $f_multi ? 'display:block' : 'display:none'; ?>">
            <label>Twitch-Kanäle konfigurieren</label>
            <div class="grid-layout">
                <?php foreach($channels as $index => $c): 
                    $cid = $c['id'];
                    $isChecked = in_array($cid, $f_sel_chans);
                    $cTitle = $f_multi_titles[$cid] ?? '';
                ?>
                    <div class="tile <?php echo $isChecked ? 'selected' : ''; ?>" id="tile_multi_<?php echo $cid; ?>">
                        <label class="tile-label" style="cursor:pointer;">
                            <span>📺 <?php echo htmlspecialchars($c['name']); ?></span>
                            <input type="checkbox" name="channels[]" value="<?php echo $cid; ?>" onchange="updateMultiItem(<?php echo $cid; ?>, this.checked)" <?php echo $isChecked ? 'checked' : ''; ?>>
                        </label>
                        <div class="tile-title-input">
                            <input type="text" name="multi_titles[<?php echo $cid; ?>]" id="title_<?php echo $cid; ?>" data-tisch="Tisch <?php echo ($index + 1); ?>" class="multi-title-field" value="<?php echo htmlspecialchars($cTitle); ?>" placeholder="Individueller Titel für diesen Kanal">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <label>Sende-Modus</label>
        <select name="stream_target" id="field_target">
            <option value="twitch" <?php echo $f_target == 'twitch' ? 'selected' : ''; ?>>🌐 Öffentlich auf Twitch übertragen</option>
            <option value="internal" <?php echo $f_target == 'internal' ? 'selected' : ''; ?>>🔒 Interner Testlauf</option>
        </select>
        <button type="submit" name="add_booking" id="submitBtn" class="btn-green">Termin verbindlich speichern</button>
    </form>
</div>

<div class="card">
    <h2>Deine reservierten Termine</h2>
    <div style="overflow-x: auto;">
        <table>
            <thead><tr><th>Zeit & Datum</th><th>Kanal</th><th>Status</th><th>Begegnung</th><th>Verwaltung</th></tr></thead>
            <tbody>
                <?php foreach($my_bookings as $b): 
                    $js_data = json_encode(['id'=>$b['id'],'date'=>date('Y-m-d',strtotime($b['start_time'])),'start'=>date('H:i',strtotime($b['start_time'])),'end'=>date('H:i',strtotime($b['end_time'])),'title'=>$b['stream_title'],'chan_id'=>$b['channel_id'],'internal'=>$b['internal_only']]);
                ?>
                <tr>
                    <td><strong><?php echo date('d.m.Y', strtotime($b['start_time'])); ?></strong><br><small><?php echo date('H:i', strtotime($b['start_time'])); ?> - <?php echo date('H:i', strtotime($b['end_time'])); ?></small></td>
                    <td class="chan-name">📺 <?php echo htmlspecialchars($b['chan_name']); ?></td>
                    <td><span class="badge <?php echo $b['internal_only'] ? 'badge-internal' : 'badge-live'; ?>"><?php echo $b['internal_only'] ? 'TEST' : 'LIVE'; ?></span></td>
                    <td style="color:#bbb; font-size:0.9rem;"><?php echo htmlspecialchars($b['stream_title']); ?></td>
                    <td>
                        <div style="white-space: nowrap;">
                            <span class="btn-action btn-edit" onclick='editBooking(<?php echo $js_data; ?>)'>Bearbeiten</span>
                            <a href="?del=<?php echo $b['id']; ?>" class="btn-action btn-storno" onclick="return confirm('Stornieren?')">Stornieren</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function updateSingleSelection(val) {
    document.querySelectorAll('#single-channel-container .tile').forEach(t => t.classList.remove('selected'));
    document.getElementById('label_single_' + val).classList.add('selected');
}

function updateMultiItem(id, isChecked) {
    const tile = document.getElementById('tile_multi_' + id);
    if(isChecked) {
        tile.classList.add('selected');
        const mainTitle = document.getElementById('mainTitle').value;
        const input = document.getElementById('title_' + id);
        if (mainTitle.trim() !== "" && input.value.trim() === "") {
            input.value = mainTitle + " - " + input.getAttribute('data-tisch');
        }
    } else { tile.classList.remove('selected'); }
}

function syncTitles() {
    const mainTitle = document.getElementById('mainTitle').value;
    document.querySelectorAll('.multi-title-field').forEach(input => {
        const parentTile = input.closest('.tile');
        if (parentTile && parentTile.classList.contains('selected') && mainTitle.trim() !== "") {
            input.value = mainTitle + " - " + input.getAttribute('data-tisch');
        }
    });
}

function toggleMode() {
    const isMulti = document.getElementById('multiToggle').checked;
    document.getElementById('matrix-container').style.display = isMulti ? 'block' : 'none';
    document.getElementById('single-channel-container').style.display = isMulti ? 'none' : 'block';
}

function editBooking(data) {
    document.getElementById('formAnchor').scrollIntoView({ behavior: 'smooth' });
    document.getElementById('editIndicator').style.display = 'block';
    document.getElementById('formTitle').innerText = "Termin anpassen";
    document.getElementById('editIdField').value = data.id;
    document.getElementById('field_date').value = data.date;
    document.getElementById('field_start').value = data.start;
    document.getElementById('field_end').value = data.end;
    document.getElementById('mainTitle').value = data.title;
    document.getElementById('field_target').value = (data.internal == 1) ? 'internal' : 'twitch';
    
    document.querySelectorAll('input[name="single_channel"]').forEach(r => {
        if(r.value == data.chan_id) { r.checked = true; updateSingleSelection(r.value); }
    });
    document.getElementById('multiToggle').checked = false;
    toggleMode();
}

function cancelEdit() { location.reload(); }

// Tauscht im Formular einen belegten Kanal gegen einen freien aus und sendet neu ab
function swapChannel(oldChanId, newChanId) {
    const isMulti = document.getElementById('multiToggle').checked;
    
    if(isMulti) {
        // Im Multimodus: Alten Haken weg, neuen Haken hin
        document.querySelectorAll('input[name="channels[]"]').forEach(cb => {
            if(cb.value == oldChanId) cb.checked = false;
            if(cb.value == newChanId) cb.checked = true;
        });
    } else {
        // Im Einzelmodus: Radiobutton umschalten
        document.querySelectorAll('input[name="single_channel"]').forEach(r => { 
            if(r.value == newChanId) r.checked = true; 
        });
    }
    document.getElementById('bookingForm').submit();
}

window.onload = function() {
    toggleMode();
    if(document.getElementById('editIdField').value !== "") {
        document.getElementById('editIndicator').style.display = 'block';
    }
};
</script>
</body>
</html>
