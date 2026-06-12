<?php
/**
 * Quickline Festnetz-Anrufliste Bridge für Home Assistant
 * Version: 10.0 (funktioniert: Basic-Client-Header + Token im Query-Parameter)
 *
 * Gelöstes Auth-Schema der public-api (aus den echten Request-Headern):
 * GET .../GetTelCallList?access_token=<TOKEN>&TelLineId=<ID>
 * Authorization: Basic cWxfY29ja3BpdDpjb2NrcGl0X3B3   (= ql_cockpit:cockpit_pw)
 * Origin:  https://my.quickline.ch
 * Referer: https://my.quickline.ch/
 * Der Basic-Header authentifiziert den CLIENT, das access_token im Query den
 * NUTZER. Beides zusammen ist nötig. Deshalb scheiterten frühere Versuche:
 * - ohne Authorization-Header -> "no auth. header"
 * - mit Bearer/Token im Header -> "invalid auth. header" (API will "Basic")
 *
 * Token-Beschaffung (OAuth-Login-Flow):
 * 1. Authorize-Seite laden, Login-Formular auslesen, Login absenden.
 * 2. code= aus der Redirect-Kette abfangen (das IST das Token).
 * 3. Token per grant_type verify_token serverseitig aktivieren.
 * 4. Anrufliste abrufen, Token cachen.
 */

header('Content-Type: application/json; charset=utf-8');

// --- 1. KONFIGURATION ---
define('HA_API_TOKEN', 'YOUR_SECRET_BRIDGE_API_TOKEN'); // Frei wählbarer Token für die Absicherung dieser Bridge
define('QL_USER', 'YOUR_QUICKLINE_USERNAME');           // Dein Quickline-Benutzername
define('QL_PASS', 'YOUR_QUICKLINE_PASSWORD');           // Dein Quickline-Passwort
define('QL_LINE_ID', 'YOUR_TELEPHONE_LINE_ID');         // Deine spezifische TelLineId von Quickline. Zu finden im my.quickline.ch (Abos > Quickline Festnetz > Anrufliste, dann die URL https://my.quickline.ch/de/abos/festnetz/anrufliste?telLineId=XXXXX (XXXXX Ist deine LineID)

define('QL_AUTHORIZE_URL', 'https://login.quickline.ch/Authorize/Index?response_type=login&client_id=ql_cockpit&redirect_uri=https://my.quickline.ch/de');
define('QL_TOKEN_ENDPOINT', 'https://login-api.quickline.ch/V00/00/ManageToken/Token');
define('QL_CLIENT_BASIC', 'cWxfY29ja3BpdDpjb2NrcGl0X3B3'); // ql_cockpit:cockpit_pw
define('QL_CLIENT_ID', 'ql_cockpit');


// --- 2. SICHERHEITS-CHECK ---
$headers = getallheaders();
$authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if ($authHeader !== 'Bearer ' . HA_API_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized - Ungültiger API-Token']);
    exit;
}

$cookieFile = __DIR__ . '/quickline_cookie.txt';
$tokenFile  = __DIR__ . '/quickline_token.json';

// --- 3. HELFER-FUNKTIONEN ---

/**
 * HTTP-Request mit cURL, OHNE automatisches Redirect-Following.
 */
function curlOnce($url, $postBody = null, $cookieFile = null, $extraHeaders = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    if ($cookieFile !== null) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $defaultHeaders = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,application/json,text/plain,*/*;q=0.8',
        'Accept-Language: de-CH,de;q=0.9,en-US;q=0.8,en;q=0.7'
    ];
    if (!empty($extraHeaders)) {
        $defaultHeaders = array_merge($defaultHeaders, $extraHeaders);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeaders);

    if ($postBody !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
    }

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        return ['error' => true, 'message' => $error_msg, 'headers' => '', 'body' => '', 'code' => 0, 'url' => $url];
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'error'   => false,
        'message' => '',
        'headers' => substr($response, 0, $headerSize),
        'body'    => substr($response, $headerSize),
        'code'    => $httpCode,
        'url'     => $url
    ];
}

/**
 * Sucht einen GUID-code in beliebigem Text.
 */
function findCode($text) {
    $guid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    if ($text !== '' && preg_match('/[?&]code=(' . $guid . ')/i', (string)$text, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Nächstes Redirect-Ziel ermitteln (Location-Header, Meta-Refresh, JS).
 */
function findNextUrl($response) {
    if (preg_match('/^\s*Location:\s*(\S+)/mi', $response['headers'], $m)) {
        return trim($m[1]);
    }
    $body = $response['body'];
    if (preg_match('/<meta[^>]*http-equiv=["\']?refresh["\']?[^>]*content=["\'][^"\']*url=([^"\'>\s]+)/i', $body, $m)) {
        return html_entity_decode(trim($m[1]), ENT_QUOTES);
    }
    if (preg_match('/(?:window\.location(?:\.href)?|location\.href|location\.replace)\s*(?:=|\()\s*["\']([^"\']+)["\']/i', $body, $m)) {
        return html_entity_decode(trim($m[1]), ENT_QUOTES);
    }
    return '';
}

/**
 * Relative URL gegen Basis auflösen.
 */
function resolveUrl($url, $base) {
    if ($url === '') { return ''; }
    if (preg_match('/^https?:\/\//i', $url)) { return $url; }
    $parts  = parse_url($base);
    $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
    $host   = isset($parts['host']) ? $parts['host'] : '';
    if (strpos($url, '//') === 0) { return $scheme . ':' . $url; }
    if (strpos($url, '/') === 0)  { return $scheme . '://' . $host . $url; }
    $path = isset($parts['path']) ? $parts['path'] : '/';
    $dir  = substr($path, 0, strrpos($path, '/') + 1);
    return $scheme . '://' . $host . $dir . $url;
}

/**
 * Redirect-Kette manuell verfolgen und code abfangen.
 */
function followChainForCode($startResponse, $startUrl, $cookieFile, $maxHops = 12) {
    $visited = [];
    $code = findCode($startResponse['headers']);
    if ($code === '') { $code = findCode($startResponse['url']); }
    if ($code === '') { $code = findCode($startResponse['body']); }
    if ($code !== '') { return ['code' => $code, 'visited' => [$startUrl]]; }

    $response = $startResponse;
    $current  = $startUrl;

    for ($i = 0; $i < $maxHops; $i++) {
        $next = findNextUrl($response);
        if ($next === '') { break; }
        $next = resolveUrl($next, $current);
        if ($next === '' || in_array($next, $visited, true)) { break; }
        $visited[] = $next;

        $code = findCode($next);
        if ($code !== '') { return ['code' => $code, 'visited' => $visited]; }

        $response = curlOnce($next, null, $cookieFile);
        $current  = $next;

        $code = findCode($response['headers']);
        if ($code === '') { $code = findCode($response['body']); }
        if ($code !== '') { return ['code' => $code, 'visited' => $visited]; }
    }
    return ['code' => '', 'visited' => $visited];
}

/**
 * Login-Formular auslesen (action + hidden-Felder).
 */
function parseLoginForm($html, $baseUrl) {
    $fields = [];
    if (preg_match_all('/<input\b[^>]*>/i', $html, $inputs)) {
        foreach ($inputs[0] as $input) {
            if (stripos($input, 'type="hidden"') === false && stripos($input, "type='hidden'") === false) {
                continue;
            }
            $name = ''; $value = '';
            if (preg_match('/\bname=["\']([^"\']+)["\']/i', $input, $n)) {
                $name = html_entity_decode($n[1], ENT_QUOTES);
            }
            if (preg_match('/\bvalue=["\']([^"\']*)["\']/i', $input, $v)) {
                $value = html_entity_decode($v[1], ENT_QUOTES);
            }
            if ($name !== '') { $fields[$name] = $value; }
        }
    }
    $action = '';
    if (preg_match('/<form\b[^>]*\baction=["\']([^"\']+)["\'][^>]*>/i', $html, $fm)) {
        $action = html_entity_decode($fm[1], ENT_QUOTES);
    }
    $action = resolveUrl($action, $baseUrl);
    if ($action === '') { $action = 'https://login.quickline.ch/Authorize'; }
    return ['action' => $action, 'fields' => $fields];
}

/**
 * Token serverseitig aktivieren (grant_type verify_token), wie das Portal.
 */
function verifyToken($token) {
    $body = json_encode([
        'grant_type'   => 'verify_token',
        'access_token' => $token,
        'clientId'     => QL_CLIENT_ID
    ]);
    $response = curlOnce(QL_TOKEN_ENDPOINT, $body, null, [
        'Authorization: Basic ' . QL_CLIENT_BASIC,
        'Content-Type: application/json',
        'Accept: application/json, text/plain, */*',
        'Origin: https://my.quickline.ch',
        'Referer: https://my.quickline.ch/'
    ]);
    return ($response['code'] >= 200 && $response['code'] < 300);
}

/**
 * Anrufliste abrufen: Token im Query-Parameter, Client per Basic-Header.
 */
function fetchCallList($token) {
    $apiUrl = 'https://public-api.quickline.ch/00/CustomerCenterApi/09/Landline/GetTelCallList'
            . '?access_token=' . urlencode($token)
            . '&TelLineId=' . urlencode(QL_LINE_ID);

    $response = curlOnce($apiUrl, null, null, [
        'Authorization: Basic ' . QL_CLIENT_BASIC,
        'Accept: application/json, text/plain, */*',
        'Origin: https://my.quickline.ch',
        'Referer: https://my.quickline.ch/'
    ]);

    if ($response['error']) {
        return ['ok' => false, 'message' => $response['message'], 'data' => null, 'raw' => null];
    }

    $jsonData = json_decode($response['body'], true);
    if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['TelCallInfos'])) {
        return ['ok' => true, 'message' => '', 'data' => $jsonData, 'raw' => $response['body']];
    }
    return ['ok' => false, 'message' => 'Antwort ohne TelCallInfos', 'data' => null, 'raw' => $response['body']];
}

/**
 * Token speichern (chmod 600).
 */
function saveToken($tokenFile, $token) {
    file_put_contents($tokenFile, json_encode([
        'access_token' => $token,
        'obtained_at'  => date('c')
    ]));
    @chmod($tokenFile, 0600);
}

// --- 4. ABLAUF-STEUERUNG ---

$jsonData        = null;
$usedCachedToken = false;

// VERSUCH A: Gecachtes Token testen
if (file_exists($tokenFile) && filesize($tokenFile) > 0) {
    $cached = json_decode(file_get_contents($tokenFile), true);
    if (is_array($cached) && !empty($cached['access_token'])) {
        $result = fetchCallList($cached['access_token']);
        if ($result['ok']) {
            $jsonData        = $result['data'];
            $usedCachedToken = true;
        }
    }
}

// VERSUCH B: Voller Login-Flow
if ($jsonData === null) {

    if (file_exists($cookieFile)) {
        unlink($cookieFile);
    }

    $loginPage    = curlOnce(QL_AUTHORIZE_URL, null, $cookieFile);
    $loginPageUrl = QL_AUTHORIZE_URL;

    if ($loginPage['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Fehler beim Laden der Authorize-Seite: ' . $loginPage['message']]);
        exit;
    }

    $hop = 0;
    while ($loginPage['code'] >= 300 && $loginPage['code'] < 400 && $hop < 5) {
        $next = findNextUrl($loginPage);
        if ($next === '') { break; }
        $next = resolveUrl($next, $loginPageUrl);
        $loginPage    = curlOnce($next, null, $cookieFile);
        $loginPageUrl = $next;
        $hop++;
        if ($loginPage['error']) { break; }
    }

    $form = parseLoginForm($loginPage['body'], $loginPageUrl);

    if (empty($form['fields']['__RequestVerificationToken'])) {
        http_response_code(500);
        echo json_encode([
            'error'     => 'Login-Formular bzw. CSRF-Token nicht gefunden.',
            'login_url' => $loginPageUrl,
            'debug_html_snippet' => substr(strip_tags($loginPage['body']), 0, 500)
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $postFields = $form['fields'];
    $postFields['UserName'] = QL_USER;
    $postFields['Password'] = QL_PASS;
    if (!isset($postFields['IsPermanentLogin'])) {
        $postFields['IsPermanentLogin'] = 'false';
    }

    $loginResponse = curlOnce(
        $form['action'],
        http_build_query($postFields),
        $cookieFile,
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: https://login.quickline.ch',
            'Referer: ' . $loginPageUrl
        ]
    );

    if ($loginResponse['error']) {
        http_response_code(500);
        echo json_encode(['error' => 'Fehler beim Absenden des Logins: ' . $loginResponse['message']]);
        exit;
    }

    $chain = followChainForCode($loginResponse, $form['action'], $cookieFile);
    $token = $chain['code'];

    if ($token === '') {
        $portalAfter = curlOnce('https://my.quickline.ch/de', null, $cookieFile);
        $chain2 = followChainForCode($portalAfter, 'https://my.quickline.ch/de', $cookieFile);
        $token  = $chain2['code'];
    }

    if ($token === '') {
        http_response_code(502);
        echo json_encode([
            'error'   => 'Login durchgelaufen, aber kein Token (code=) gefunden.',
            'hinweis' => 'Prüfe Benutzername und Passwort.',
            'besuchte_urls' => $chain['visited']
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    verifyToken($token);
    $result = fetchCallList($token);

    if (!$result['ok']) {
        http_response_code(502);
        $raw = json_decode((string)$result['raw'], true);
        echo json_encode([
            'error'         => 'Token erhalten, aber der API-Aufruf schlug fehl.',
            'raw_response'  => $raw !== null ? $raw : substr(strip_tags((string)$result['raw']), 0, 500),
            'token_genutzt' => substr($token, 0, 8) . '...'
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jsonData = $result['data'];
    saveToken($tokenFile, $token);
}

// --- 5. DATEN FORMATIEREN ---
$formattedCalls = [];
foreach ($jsonData['TelCallInfos'] as $call) {
    switch ($call['Direction']) {
        case 0:
            $type = 'Eingehend (Beantwortet)';
            $icon = 'mdi:phone-incoming';
            break;
        case 1:
            $type = 'Verpasst';
            $icon = 'mdi:phone-missed';
            break;
        case 2:
            $type = 'Ausgehend';
            $icon = 'mdi:phone-outgoing';
            break;
        default:
            $type = 'Unbekannt';
            $icon = 'mdi:phone';
    }

    $number = $call['Number'];
    if (strpos($number, '0041') === 0) {
        $number = '0' . substr($number, 4);
    }

    $dateFormatted = date('d.m.Y H:i', strtotime($call['DateTime']));

    $formattedCalls[] = [
        'timestamp'  => $call['DateTime'],
        'date_human' => $dateFormatted,
        'number'     => $number,
        'type'       => $type,
        'icon'       => $icon,
        'counter'    => $call['Counter']
    ];
}

$lastCallStatus = !empty($formattedCalls)
    ? $formattedCalls[0]['number'] . ' (' . $formattedCalls[0]['type'] . ')'
    : 'Keine Anrufe';

echo json_encode([
    'cached_token' => $usedCachedToken,
    'last_updated' => date('c'),
    'last_call'    => $lastCallStatus,
    'calls'        => $formattedCalls
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
