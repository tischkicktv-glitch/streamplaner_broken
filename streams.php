<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
date_default_timezone_set('Europe/Berlin');

try {
    $db = new PDO('sqlite:kicker.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) { die("Datenbank-Fehler"); }

// --- ZEIT-VALIDIERTE LIVE-LOGIK ---
function isCurrentlyStreaming($chan_name, $start_time, $end_time) {
    $logfile = 'logs.txt';
    if (!file_exists($logfile)) return false;
    $content = trim(file_get_contents($logfile));
    if (empty($content)) return false;
    $lines = array_reverse(explode("\n", $content)); 

    foreach ($lines as $line) {
        if (empty($line)) continue;
        if (strpos($line, "| $chan_name |") !== false) {
            if (strpos($line, 'STOP') !== false) return false;
            if (strpos($line, 'OK') !== false) {
                $parts = explode(' | ', $line);
                $log_ts = strtotime(date('Y-m-d') . ' ' . trim($parts[0]));
                $booking_start = strtotime($start_time);
                $booking_end = strtotime($end_time);
                if ($booking_end <= $booking_start) { $booking_end += 86400; }
                return ($log_ts >= ($booking_start - 900) && $log_ts <= ($booking_end + 300));
            }
        }
    }
    return false;
}

if (isset($_GET['check_update'])) {
    echo file_exists('last_change.txt') ? file_get_contents('last_change.txt') : '0';
    exit;
}

$today_limit = date('Y-m-d 00:00:00');
$query = "SELECT b.*, t.name as team_name, t.logo_url, c.name as chan_name 
          FROM bookings b 
          JOIN teams t ON b.team_id = t.id 
          JOIN channels c ON b.channel_id = c.id 
          WHERE b.end_time >= ? 
          AND t.is_visible = 1 
          AND b.internal_only = 0 
          ORDER BY b.start_time ASC, b.team_id ASC";

$bookings = $db->prepare($query);
$bookings->execute([$today_limit]);
$raw_plan = $bookings->fetchAll(PDO::FETCH_ASSOC);

// --- GRUPPIERUNG DER EVENTS (Tische zusammenfassen) ---
$plan = [];
foreach ($raw_plan as $b) {
    $group_id = date('YmdHi', strtotime($b['start_time'])) . "_" . $b['team_id'];
    if (!isset($plan[$group_id])) {
        $plan[$group_id] = [
            'team_name' => $b['team_name'],
            'logo_url' => $b['logo_url'],
            'start_time' => $b['start_time'],
            'end_time' => $b['end_time'],
            'stream_title' => $b['stream_title'],
            'tables' => []
        ];
    }
    $plan[$group_id]['tables'][] = [
        'chan_name' => $b['chan_name'],
        'is_live' => isCurrentlyStreaming($b['chan_name'], $b['start_time'], $b['end_time'])
    ];
}

$monate = ["January" => "Januar", "February" => "Februar", "March" => "März", "April" => "April", "May" => "Mai", "June" => "Juni", "July" => "Juli", "August" => "August", "September" => "September", "October" => "Oktober", "November" => "November", "December" => "Dezember"];

$nav_months = [];
foreach($plan as $event) {
    $m_key = date('F Y', strtotime($event['start_time']));
    if(!isset($nav_months[$m_key])) {
        $m_name = date('F', strtotime($event['start_time']));
        $nav_months[$m_key] = $monate[$m_name] . " " . date('Y', strtotime($event['start_time']));
    }
}

if (isset($_GET['ajax'])) { renderContent($plan, $monate); exit; }

function renderContent($plan, $monate) {
    $currentMonth = "";
    foreach($plan as $event): 
        $now = time();
        $start_ts = strtotime($event['start_time']);
        $end_ts = strtotime($event['end_time']);
        if ($end_ts <= $start_ts) { $end_ts += 86400; }

        $thisMonthKey = date('F Y', $start_ts);
        if ($thisMonthKey != $currentMonth) {
            $m_name = date('F', $start_ts);
            echo '<div class="month-divider" id="month-' . md5($thisMonthKey) . '">' . $monate[$m_name] . ' ' . date('Y', $start_ts) . '</div>';
            $currentMonth = $thisMonthKey;
        }

        $any_live = false;
        foreach($event['tables'] as $t) { if($t['is_live']) $any_live = true; }
        $is_today = (date('Y-m-d', $start_ts) === date('Y-m-d'));

        if ($any_live) { $class = 'status-live'; $status = '<div class="live-badge">● LIVE</div>'; }
        elseif ($now < $start_ts) { $class = 'status-upcoming'; $status = '<span class="status-text">GEPLANT</span>'; }
        elseif ($now > $end_ts) { $class = 'status-finished'; $status = '<span class="status-text">BEENDET</span>'; }
        else { $class = 'status-upcoming'; $status = '<span class="status-text ready-badge">BEREIT</span>'; }
    ?>
        <div class="stream-card <?php echo $class; ?>">
            <div class="time-box">
                <span class="date-label"><?php echo $is_today ? 'HEUTE' : date('d.m.', $start_ts); ?></span>
                <span class="time-start"><?php echo date('H:i', $start_ts); ?> Uhr</span>
                <span class="time-end">bis <?php echo date('H:i', $end_ts); ?></span>
            </div>
            <div class="team-logo-container">
                <?php if(!empty($event['logo_url']) && file_exists($event['logo_url'])): ?>
                    <img src="<?php echo htmlspecialchars($event['logo_url']); ?>" alt="Logo">
                <?php else: ?><div style="font-size: 2rem; opacity: 0.2;">⚽</div><?php endif; ?>
            </div>
            <div class="info-box">
                <p class="team-name"><?php echo htmlspecialchars($event['team_name']); ?></p>
                <p class="stream-title"><?php echo htmlspecialchars($event['stream_title']); ?></p>
                <div style="display: flex; gap: 5px; flex-wrap: wrap; margin-top: 5px;">
                    <?php foreach($event['tables'] as $table): 
                        $is_time_active = ($now >= $start_ts && $now <= $end_ts);
                        $show_dot = ($table['is_live'] || $is_time_active);
                        $dot_color = $table['is_live'] ? '#8fce00' : '#ffcc00';
                    ?>
                        <a href="https://twitch.tv/<?php echo strtolower($table['chan_name']); ?>" target="_blank" class="channel-tag" style="text-decoration: none;">
                            <?php if($show_dot): ?>
                                <span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:<?php echo $dot_color; ?>; margin-right:5px; box-shadow: 0 0 4px <?php echo $dot_color; ?>;"></span>
                            <?php endif; ?>
                            📺 <?php echo htmlspecialchars($table['chan_name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="status-area"><?php echo $status; ?></div>
        </div>
    <?php endforeach;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TischKickTV - Sendeplan</title>
    <style>
        html { scroll-behavior: smooth; }
        body { font-family: 'Segoe UI', sans-serif; background: #222222; color: #fff; margin: 0; padding: 0; }
        .container { max-width: 900px; margin: auto; padding: 0 20px; }
        .header-area { text-align: center; margin-top: 15px; }
        .logo-img { width: 120px; }
        h1 { font-size: 1.2rem; text-transform: uppercase; letter-spacing: 2px; margin: 5px 0; }
        .accent-line { width: 40px; height: 3px; background: #1b5e20; margin: 8px auto; border-radius: 2px; }

        .sticky-nav-container { position: sticky; top: 0; z-index: 100; background: rgba(34, 34, 34, 0.95); backdrop-filter: blur(10px); padding: 8px 0; border-bottom: 1px solid #444; margin-bottom: 15px; }
        .month-nav { text-align: center; }
        .nav-link { display: inline-block; color: #ddd; text-decoration: none; font-size: 0.7rem; font-weight: bold; padding: 5px 12px; border: 1px solid #555; border-radius: 20px; margin: 3px; background: #333333; }

        .month-divider { display: flex; align-items: center; margin: 20px 0 12px 0; color: #8fce00; font-weight: bold; text-transform: uppercase; letter-spacing: 2px; font-size: 0.85rem; scroll-margin-top: 70px; }
        .month-divider::after { content: ""; flex-grow: 1; height: 1px; background: #555; margin-left: 15px; }

        .stream-card { background: #333333; border-radius: 10px; margin-bottom: 10px; display: flex; align-items: center; padding: 12px 18px; border: 1px solid #444; text-decoration: none; color: inherit; transition: transform 0.2s; position: relative; overflow: hidden; }
        .status-live { border-left: 5px solid #1b5e20; background: linear-gradient(90deg, rgba(143, 206, 0, 0.15) 0%, #333333 100%); }
        .status-upcoming { border-left: 5px solid #2e7d32; }
        .status-finished { border-left: 5px solid #777; opacity: 0.7; }

        .time-box { min-width: 95px; text-align: center; border-right: 1px solid #555; margin-right: 18px; padding-right: 12px; }
        .date-label { font-size: 1.1rem; font-weight: bold; display: block; margin-bottom: 4px; line-height: 1; }
        .time-start { font-size: 1.1rem; display: block; color: #ccc; line-height: 1; }
        .time-end { font-size: 0.75rem; color: #999; }

        .team-logo-container { flex-shrink: 0; width: 70px; height: 70px; margin-right: 18px; display: flex; align-items: center; justify-content: center; }
        .team-logo-container img { max-width: 100%; max-height: 100%; object-fit: contain; }

        .info-box { flex-grow: 1; min-width: 0; }
        .team-name { font-size: 1.1rem; font-weight: bold; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .stream-title { font-size: 0.85rem; color: #8fce00; margin: 2px 0 6px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .channel-tag { font-size: 0.7rem; background: #222; padding: 3px 10px; border-radius: 15px; color: #8fce00; border: 1px solid #8fce00; display: inline-flex; align-items: center; margin-bottom: 2px; }
        
        .status-area { min-width: 95px; text-align: right; }
        .live-badge { background: #ff4d4d; color: white; padding: 6px 10px; border-radius: 6px; font-weight: bold; font-size: 0.7rem; animation: pulse 1.5s infinite; text-align: center; display: inline-block; }
        .status-text { font-weight: bold; font-size: 0.7rem; letter-spacing: 1px; color: #bbb; }
        .ready-badge { color: #8fce00; border: 1px solid #8fce00; padding: 4px 10px; border-radius: 6px; }

        @media (max-width: 600px) {
            .stream-card { flex-wrap: wrap; padding: 12px; }
            .time-box { border-right: none; text-align: left; min-width: 100%; margin-bottom: 8px; padding: 0; margin-right: 0; }
            .date-label, .time-start { display: inline; font-size: 1rem; margin-right: 5px; }
            .time-end { display: block; margin-top: 2px; }
            .status-area { position: absolute; top: 12px; right: 12px; min-width: auto; }
            .team-logo-container { width: 50px; height: 50px; margin-right: 12px; }
            .info-box { width: calc(100% - 65px); }
            .team-name { white-space: normal; font-size: 1rem; }
        }

        #backToTop { position: fixed; bottom: 25px; right: 25px; background: #2e7d32; color: white; width: 40px; height: 40px; border-radius: 50%; display: none; justify-content: center; align-items: center; text-decoration: none; z-index: 1000; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 77, 77, 0.4); } 70% { box-shadow: 0 0 0 8px rgba(255, 77, 77, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 77, 77, 0); } }
    </style>
</head>
<body>
<div class="container" style="padding-top: 10px;">
    <div class="header-area"><img src="Avatar_TischKick_Wort-Bild_neu.png" class="logo-img"><h1>Sendeplan & Termine</h1><div class="accent-line"></div></div>
</div>

<?php if(count($plan) > 0): ?>
<div class="sticky-nav-container"><div class="month-nav">
    <?php foreach($nav_months as $id => $label): ?><a href="#month-<?php echo md5($id); ?>" class="nav-link"><?php echo $label; ?></a><?php endforeach; ?>
</div></div>
<div class="container" id="dynamic-content"><?php renderContent($plan, $monate); ?></div>
<?php else: ?>
<div class="container"><div style="text-align:center; padding:40px; background: #333333; border-radius: 12px; border: 1px dashed #666; color:#aaa;">Keine Streams geplant.</div></div>
<?php endif; ?>

<a href="#" id="backToTop">↑</a>

<script>
    let lastTs = "<?php echo file_exists('last_change.txt') ? file_get_contents('last_change.txt') : '0'; ?>";
    async function checkUpdate() {
        try {
            const r = await fetch('streams.php?check_update=1');
            const ts = await r.text();
            if (ts !== lastTs) {
                const c = await fetch('streams.php?ajax=1');
                document.getElementById('dynamic-content').innerHTML = await c.text();
                lastTs = ts;
            }
        } catch(e){}
    }
    setInterval(checkUpdate, 30000);

    const btn = document.getElementById('backToTop');
    window.onscroll = () => btn.style.display = (window.scrollY > 300) ? "flex" : "none";
    btn.onclick = (e) => { e.preventDefault(); window.scrollTo({top: 0, behavior: 'smooth'}); };
</script>
</body>
</html>
