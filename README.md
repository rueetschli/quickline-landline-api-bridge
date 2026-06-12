# quickline-landline-api-bridge

Eine leichtgewichtige PHP-Lösung, um die Festnetz-Anrufliste aus dem Quickline-Kundencenter (myQuickline) automatisiert abzufragen und strukturiert für Smart-Home-Systeme (wie Home Assistant) oder autarke Web-Applikationen zur Verfügung zu stellen.

Da Quickline im Privatkundenbereich standardmässig keine offizielle, dokumentierte API oder SIP-Credentials anbietet, emuliert diese Brücke den OAuth2-Login-Flow des offiziellen Dashboards und fragt den dahinterliegenden JSON-Schnittstellen-Endpoint ab.

## Features

- **Automatisierter Login-Flow:** Behandelt CSRF-Verifizierungstoken, folgt Redirect-Ketten und extrahiert gültige API-Zugangstoken im Hintergrund.
- **Token-Caching:** Minimiert die Anzahl der Login-Anfragen an die Provider-Infrastruktur durch lokale Zwischenspeicherung aktiver Sitzungstoken.
- **Home Assistant REST-Kompatibilität:** Bereitet Daten als standardisiertes JSON-Objekt auf, das nativ über einen `rest`-Sensor eingelesen werden kann.
- **Integrierte Mobile Web-App (PWA):** Enthält eine passwortgeschützte, datenschutzfreundliche iOS/Android-optimierte Oberfläche im Apple-Design (ohne externe CDN-Abhängigkeiten).

## Komponenten

1. `quickline_bridge.php`: Die API-Brücke. Übernimmt die Authentifizierung, das Session-Handling und liefert die bereinigte Anrufliste als geschützte JSON-Ressource aus.
2. `index.php`: Eine eigenständige, mobile Web-Oberfläche. Dient der schnellen mobilen Übersicht inklusive Direktwahl-Optionen über das Smartphone.

## Installation & Konfiguration

### 1. API-Brücke einrichten
Lade die Datei `quickline_bridge.php` auf einen PHP-fähigen Webserver hoch. Öffne die Datei und passe die Konstanten an:

```php
define('HA_API_TOKEN', 'DEIN_FREI_WAEHLBARER_SICHERHEITS_TOKEN');
define('QL_USER', 'DEIN_QUICKLINE_BENUTZERNAME');
define('QL_PASS', 'DEIN_QUICKLINE_PASSWORT');
define('QL_LINE_ID', 'DEINE_FESTNETZ_LINE_ID');
```

*Hinweis zur Ermittlung der QL_LINE_ID: >* Die spezifische Leitungs-ID kann über das Quickline-Kundencenter ausgelesen werden. Melden Sie sich hierzu unter ```my.quickline.ch``` an und navigieren Sie über den Menüpfad ```Abos``` → ```Festnetz``` zur ```Anrufliste.``` Die benötigte ID befindet sich am Ende der Browser-Adresszeile als numerischer Wert des URL-Parameters telLineId (z. B. ```https://my.quickline.ch/.../anrufliste?telLineId=123456```).

*Hinweis zu Schreibrechten:* Der Webserver benötigt Schreibrechte im entsprechenden Verzeichnis, um die Dateien ```quickline_cookie.txt``` und ```quickline_token.json``` für das Sitzungshandling erstellen zu können.

## 2. Integration in Home Assistant
Die Brücke lässt sich über die ```configuration.yaml``` als REST-Sensor registrieren:
```yaml
sensor:
  - platform: rest
    name: "Quickline Festnetz Anrufliste"
    resource: "[https://deine-domain.com/quickline_bridge.php](https://deine-domain.com/quickline_bridge.php)"
    method: GET
    headers:
      Authorization: "Bearer DEIN_FREI_WAEHLBARER_SICHERHEITS_TOKEN"
      Content-Type: "application/json"
    value_template: "{{ value_json.last_call }}"
    json_attributes:
      - calls
      - last_updated
    scan_interval: 300
Die Brücke lässt sich über die configuration.yaml als REST-Sensor registrieren:
define('QL_USER', 'DEIN_QUICKLINE_BENUTZERNAME');
define('QL_PASS', 'DEIN_QUICKLINE_PASSWORT');
define('QL_LINE_ID', 'DEINE_FESTNETZ_LINE_ID');
```

## 3. Optionale Mobile Web-App konfigurieren
Passe die index.php an, um die Benutzeroberfläche lokal oder auf einem separaten Host bereitzustellen:
```php
define('APP_PASSWORD', 'DEIN_ZUGANGSPASSWORT_FUER_DIE_WEBSITE');
define('BRIDGE_URL', '[https://deine-domain.com/quickline_bridge.php](https://deine-domain.com/quickline_bridge.php)');
define('HA_API_TOKEN', 'DEIN_FREI_WAEHLBARER_SICHERHEITS_TOKEN');
```

## Rechtlicher Hinweis / Disclaimer
Dieses Projekt steht in keiner Verbindung mit der Quickline AG. Es handelt sich um eine unabhängige Entwicklung. Die Nutzung erfolgt auf eigene Verantwortung. Änderungen an der Login-Architektur des Providers können die Funktionalität dieses Skripts beeinträchtigen.
