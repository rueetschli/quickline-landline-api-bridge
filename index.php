<?php
/**
 * Quickline Festnetz Web-App für iOS
 */

session_start();

// --- 1. KONFIGURATION ---
define('APP_PASSWORD', 'YOUR_WEBAPP_ACCESS_PASSWORD');              // Passwort für den Zugang zur mobilen Webseite
define('BRIDGE_URL', 'https://yourdomain.com/quickline_bridge.php'); // URL zu deiner gehosteten quickline_bridge.php
define('HA_API_TOKEN', 'YOUR_SECRET_BRIDGE_API_TOKEN');             // Muss exakt dem Token aus der Bridge entsprechen

// --- 2. LOGOUT LOGIK ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['authenticated']);
    session_destroy();
    header('Location: index.php');
    exit;
}

// --- 3. LOGIN LOGIK ---
$login_error = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === APP_PASSWORD) {
        $_SESSION['authenticated'] = true;
        header('Location: index.php');
        exit;
    } else {
        $login_error = true;
    }
}

// --- 4. DATEN ABFRAGEN ---
$data = null;
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, BRIDGE_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . HA_API_TOKEN,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
    }
}

// Schweizer Telefonbuch-Formatierung (z.B. 032 621 12 70)
function formatSwissNumber($num) {
    $num = trim($num);
    if (strpos($num, '0041') === 0) {
        $num = '0' . substr($num, 4);
    }
    if (preg_match('/^(0[1-9]{2})([0-9]{3})([0-9]{2})([0-9]{2})$/', $num, $matches)) {
        return $matches[1] . ' ' . $matches[2] . ' ' . $matches[3] . ' ' . $matches[4];
    }
    return $num;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Festnetz</title>
    
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Festnetz">
    
    <link rel="apple-touch-icon" href="icon.png">

    <style>
        :root {
            --bg-color: #f2f2f7;
            --card-bg: #ffffff;
            --text-main: #1c1c1e;
            --text-muted: #8e8e93;
            --border-color: #e5e5ea;
            --primary: #d30137; 
            --success: #34c759; 
            --warning: #007aff; 
            --danger: #ff3b30;  
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-color: #000000;
                --card-bg: #1c1c1e;
                --text-main: #ffffff;
                --text-muted: #8e8e93;
                --border-color: #2c2c2e;
            }
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            padding-bottom: 40px;
            padding-top: env(safe-area-inset-top);
        }

        .login-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 80vh;
            padding: 20px;
        }

        .login-card {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 30px 24px;
            width: 100%;
            max-width: 360px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            text-align: center;
        }

        .app-icon-placeholder {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, var(--primary), #a00028);
            border-radius: 16px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            box-shadow: 0 4px 10px rgba(211, 1, 55, 0.3);
        }

        h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 24px;
        }

        input[type="password"] {
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background-color: var(--bg-color);
            color: var(--text-main);
            font-size: 16px;
            text-align: center;
            margin-bottom: 14px;
            outline: none;
            transition: border-color 0.2s;
        }

        input[type="password"]:focus {
            border-color: var(--primary);
        }

        button {
            width: 100%;
            padding: 14px;
            border-radius: 10px;
            border: none;
            background-color: var(--primary);
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }

        .error-msg {
            color: var(--danger);
            font-size: 14px;
            margin-bottom: 10px;
        }

        header {
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--card-bg);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .header-title h2 {
            font-size: 22px;
            font-weight: 700;
        }

        .header-title span {
            font-size: 11px;
            color: var(--text-muted);
            display: block;
        }

        .logout-btn {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 15px;
            font-weight: 500;
            width: auto;
            padding: 0;
        }

        .list-container {
            padding: 16px;
            max-width: 600px;
            margin: 0 auto;
        }

        .call-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            margin-bottom: 8px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .call-info {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .icon-wrapper {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .status-verpasst { background-color: rgba(255, 59, 48, 0.1); color: var(--danger); }
        .status-eingehend { background-color: rgba(52, 199, 89, 0.1); color: var(--success); }
        .status-ausgehend { background-color: rgba(0, 122, 255, 0.1); color: var(--warning); }

        .number-details h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .number-details a {
            color: var(--text-main);
            text-decoration: none;
        }

        .number-details p {
            font-size: 12px;
            color: var(--text-muted);
        }

        .call-meta {
            text-align: right;
        }

        .call-time {
            font-size: 14px;
            font-weight: 500;
        }

        .call-counter {
            font-size: 11px;
            color: var(--text-muted);
            background-color: var(--bg-color);
            padding: 2px 6px;
            border-radius: 8px;
            display: inline-block;
            margin-top: 4px;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

<?php if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true): ?>
    <div class="login-container">
        <div class="login-card">
            <div class="app-icon-placeholder">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </div>
            <h1>Anrufliste</h1>
            <p class="subtitle">Bitte gib dein Zugangspasswort ein.</p>
            
            <?php if ($login_error): ?>
                <p class="error-msg">Passwort ungültig</p>
            <?php endif; ?>
            
            <form method="POST" action="index.php">
                <input type="password" name="password" placeholder="Passwort" autofocus required>
                <button type="submit">Entsperren</button>
            </form>
        </div>
    </div>

<?php else: ?>
    <header>
        <div class="header-title">
            <h2>Anrufliste</h2>
            <span>Aktualisiert: <?php echo $data ? date('H:i', strtotime($data['last_updated'])) : 'Unbekannt'; ?></span>
        </div>
        <a href="index.php?action=logout" class="logout-btn">Sperren</a>
    </header>

    <div class="list-container">
        <?php if ($data && !empty($data['calls'])): ?>
            <?php foreach ($data['calls'] as $call): 
                $statusClass = 'status-eingehend';
                $svgIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 7 22 2 17 2"></polygon><path d="M5.45 5.11L2 6.11A2 2 0 0 0 1 8.11v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3.45"></path></svg>';
                
                if (strpos($call['type'], 'Verpasst') !== false) {
                    $statusClass = 'status-verpasst';
                    $svgIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
                } elseif (strpos($call['type'], 'Eingehend') !== false) {
                    $statusClass = 'status-eingehend';
                    $svgIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="2" y1="22" x2="13" y2="11"></line><polygon points="2 17 2 22 7 22"></polygon><path d="M18.55 18.89l3.45-1v-11a2 2 0 0 0-2-2h-11a2 2 0 0 0-2 2v3.45"></path></svg>';
                } elseif (strpos($call['type'], 'Ausgehend') !== false) {
                    $statusClass = 'status-ausgehend';
                    $svgIcon = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 7 22 2 17 2"></polygon><path d="M14 2H3a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"></path></svg>';
                }
            ?>
                <div class="call-card">
                    <div class="call-info">
                        <div class="icon-wrapper <?php echo $statusClass; ?>">
                            <?php echo $svgIcon; ?>
                        </div>
                        <div class="number-details">
                            <h3>
                                <a href="tel:<?php echo $call['number']; ?>">
                                    <?php echo formatSwissNumber($call['number']); ?>
                                </a>
                            </h3>
                            <p><?php echo $call['type']; ?></p>
                        </div>
                    </div>
                    <div class="call-meta">
                        <div class="call-time"><?php echo date('H:i', strtotime($call['timestamp'])); ?></div>
                        <p style="font-size: 11px; color: var(--text-muted);"><?php echo date('d.m.', strtotime($call['timestamp'])); ?></p>
                        <?php if (intval($call['counter']) > 1): ?>
                            <span class="call-counter"><?php echo $call['counter']; ?> Min.</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-data">Keine Anrufe gefunden oder Fehler bei der Abfrage.</div>
        <?php endif; ?>
    </div>
<?php endif; ?>

</body>
</html>
