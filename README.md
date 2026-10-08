# DhGithubUpdater – Shopware-6-Plugins sicher aus GitHub installieren

🇬🇧 [English version](README.en.md)

Mit diesem Plugin installierst und aktualisierst du Shopware-6-Plugins direkt aus
GitHub-Repositories – öffentlich oder privat – ohne Shopware-Account und ohne Plugin-Store.
Es bietet die gleichen Funktionen wie
[yaetcom/ShopwareGitHubUpdater](https://github.com/yaetcom/ShopwareGitHubUpdater),
wurde aber von Grund auf neu und mit Fokus auf Sicherheit geschrieben.

## Funktionen

- **Installation aus GitHub**: In *Erweiterungen → Meine Erweiterungen* gibt es den Button
  „Aus GitHub installieren“. URL eingeben, Versionen laden, Version wählen, installieren.
- **Nur kompatible Versionen**: Es werden nur Tags (optional Branches) angeboten, deren
  `composer.json` laut `require.shopware/core` zur laufenden Shopware-Version passt
  (echte Semver-Prüfung mit `composer/semver`).
- **Updates**: Auf jeder Plugin-Karte kann ein GitHub-Repository verknüpft werden.
  „Updates prüfen“ zeigt neuere Versionen, „Aktualisieren“ spielt sie ein. Anschließend
  läuft der reguläre Shopware-Update-Prozess (inkl. Migrationen).
- **Branch-Updates**: Bei Installation aus einem Branch wird der Commit gespeichert, neue
  Commits werden als Update erkannt (muss in der Konfiguration aktiviert werden).
- **Rollback**: Schlägt ein Update beim Einlesen fehl, wird der vorherige Stand
  automatisch wiederhergestellt.

## Sicherheitskonzept

Im Original-Plugin wurden u. a. folgende Schwachstellen gefunden. Die Tabelle zeigt,
wie sie hier gelöst sind:

| Problem im Original | Risiko | Lösung in diesem Plugin |
|---|---|---|
| `exec("php bin/console plugin:install --activate $pluginName")` – Plugin-Name stammt ungeprüft aus der `composer.json` des fremden Repos | **Remote Code Execution** (Shell-Injection) beim bloßen Installieren | Es werden **keine Shell-Befehle** ausgeführt. Dateien ablegen + `PluginService::refreshPlugins()`; Installieren/Aktivieren/Update übernimmt Shopware über die eigenen, ACL-geschützten Endpunkte. Plugin-Namen werden streng validiert (`^[A-Za-z][A-Za-z0-9_]*$`). |
| `ZipArchive::extractTo()` ohne Prüfung der Einträge | **Zip-Slip**: Dateien können außerhalb von `custom/plugins` (z. B. in `public/`) geschrieben werden | Eigener Entpacker: lehnt `..`, absolute Pfade, Backslashes, Laufwerksbuchstaben, Steuerzeichen, **Symlinks** und mehrere Wurzelverzeichnisse ab. |
| Request-Parameter `name` wird direkt in Pfade eingesetzt und mit `Filesystem::remove()` gelöscht | **Beliebiges Löschen/Überschreiben** von Verzeichnissen (Path-Traversal) | Zielpfade entstehen nur aus dem validierten Plugin-Namen und werden per `realpath()` gegen `custom/plugins` geprüft. Gelöscht wird nur innerhalb des eigenen Staging-Verzeichnisses. |
| Keine ACL-Prüfung an den API-Routen | Jeder Admin-API-Benutzer bzw. jede Integration (auch mit minimalen Rechten) kann Code installieren | ACL `system.plugin_maintain` + `system.plugin_upload`, nur echte Admin-Benutzer (keine Integrationen), standardmäßig **nur Administratoren**. Respektiert `shopware.deployment.runtime_extension_management`. |
| Repository-URL per `preg_match('/github\.com\/…/')` irgendwo im String gesucht; Tags/Branches ungeprüft in URLs eingesetzt | URL-/Pfad-Manipulation der GitHub-Anfragen | Strenger Parser (nur `https://github.com/<owner>/<repo>`), Tags/Branches nur exakt aus der GitHub-API-Liste, Download immer über die **Commit-SHA**. |
| `file_get_contents()` folgt beliebigen Weiterleitungen, keine Größenlimits | SSRF, Speicher-Erschöpfung, Zip-Bomben | HTTP-Client mit Host-Positivliste auch für Redirects, Timeouts, Download- und Entpack-Limits (Größe + Dateianzahl), Streaming statt Laden in den RAM. |
| Update-Quelle (`url`) kam aus dem Request und die DAL-Entity war über die Admin-API frei beschreibbar | Untergeschobene Repositories bei Updates | Repository wird serverseitig gespeichert und beim Update nur aus der Datenbank gelesen; das Archiv muss exakt das erwartete Plugin (Name + Klasse) enthalten. Keine generisch beschreibbare Entity. |
| Rohe Exception-Meldungen an den Client | Informationspreisgabe (Pfade, interne Fehler) | Nur bewusst formulierte Meldungen; alles andere wird geloggt und generisch beantwortet. |
| Download landet direkt in `custom/plugins` | Halb entpackte/defekte Plugins bei Fehlern | Entpacken in ein von Shopware ignoriertes Staging-Verzeichnis, dann atomares `rename()`, Rollback bei Fehlern, Sperre gegen parallele Vorgänge. |
| Kein Downgrade-Schutz, keine Kompatibilitätsprüfung (`str_contains`) | Inkompatible Versionen, kaputter Shop | Semver-Prüfung mit `composer/semver`, Downgrades bei Tags werden abgelehnt. |
| GitHub-Token: nicht vorgesehen | – | Optionales Token, wird ausschließlich an `api.github.com` gesendet (nicht an Download-Hosts) und nie geloggt. |

> **Wichtig:** Ein Plugin ist ausführbarer PHP-Code mit vollem Zugriff auf deinen Shop.
> Dieses Plugin verhindert, dass der *Updater selbst* angreifbar ist – es kann aber nicht
> prüfen, ob der Code eines fremden Plugins vertrauenswürdig ist. Installiere nur aus
> Quellen, denen du vertraust, und nutze die **Repository-Positivliste**.

## Voraussetzungen

- Shopware 6.6 oder 6.7, PHP ≥ 8.2 mit `ext-zip`
- `custom/plugins` muss für den Webserver-Benutzer beschreibbar sein
- Laufzeit-Erweiterungsverwaltung aktiv (`shopware.deployment.runtime_extension_management: true`, Standard)

## Installation

```bash
cd <shopware>/custom/plugins
git clone https://github.com/DrdotHouse2106/SW6-Githubupdater.git DhGithubUpdater
cd <shopware>
bin/console plugin:refresh
bin/console plugin:install --activate DhGithubUpdater
bin/build-administration.sh   # Administration neu bauen (enthält die Oberfläche des Plugins)
bin/console cache:clear
```

Das Repository enthält nur die Quelltexte der Administration. Nach Installation oder
Update dieses Plugins muss die Administration daher einmal neu gebaut werden
(`bin/build-administration.sh` bzw. `composer run build:js:admin`).

## Konfiguration

*Einstellungen → Erweiterungen → GitHub Plugin-Updater*

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Nur Administratoren | an | Wenn aus, reichen die ACL-Rechte `system.plugin_maintain` + `system.plugin_upload`. |
| Erlaubte Repositories | leer (= alle) | Ein Eintrag pro Zeile: `owner/repo` oder `owner/*`. **Empfohlen.** |
| Installation aus Branches | aus | Branches sind keine festen Releases. Tags werden immer bevorzugt. |
| GitHub-Token | leer | Nötig für private Repositories, erhöht außerdem das API-Limit von 60 auf 5000 Anfragen/Stunde. Siehe [Private Repositories](#private-repositories). |
| Maximale Downloadgröße | 50 MB | Schutz vor übergroßen Archiven. |
| Anzahl geprüfter Tags | 15 | Jeder Tag kostet eine API-Anfrage (Ergebnisse werden pro Commit zwischengespeichert). |

## Private Repositories

Plugins aus privaten Repositories lassen sich installieren und aktualisieren, sobald in der
Plugin-Konfiguration ein GitHub-Token mit Lesezugriff hinterlegt ist.

So erstellst du ein passendes Token (GitHub → *Settings → Developer settings →
Personal access tokens → Fine-grained tokens → Generate new token*):

1. **Resource owner**: Benutzer bzw. Organisation, der die Repositories gehören.
2. **Repository access**: *Only select repositories* und nur die benötigten Repos auswählen.
3. **Permissions → Repository permissions**: nur **Contents: Read-only**
   (*Metadata: Read-only* wird automatisch gesetzt). Keine weiteren Rechte vergeben.
4. **Expiration**: ein Ablaufdatum setzen (z. B. 1 Jahr) und rechtzeitig erneuern.
5. Token in Shopware unter *Einstellungen → Erweiterungen → GitHub Plugin-Updater →
   GitHub-Token* eintragen und speichern.

Wie das Token geschützt wird:

- Es wird **ausschließlich an `api.github.com`** gesendet. Den eigentlichen Download leitet
  GitHub auf `codeload.github.com` mit einem kurzlebigen, nur für dieses Archiv gültigen
  Schlüssel weiter – das Token selbst verlässt dabei nicht den API-Aufruf.
- Es wird nie geloggt und nie an die Administration zurückgegeben (außer im
  Passwortfeld der Konfiguration).
- Es liegt wie alle Plugin-Einstellungen in der Tabelle `system_config`. Wer Zugriff auf die
  Datenbank oder Admin-Rechte für die Systemeinstellungen hat, kann es auslesen – deshalb
  nur Lesezugriff und nur die nötigen Repositories freigeben.

Zusätzlich empfiehlt sich die **Repository-Positivliste**, z. B. `DeinName/*`.

### Diesen Updater aus einem privaten Repository installieren

Ein einfaches `git clone` benötigt dann eine Anmeldung. Entweder mit Token klonen und es
anschließend wieder aus der Git-Konfiguration entfernen:

```bash
cd <shopware>/custom/plugins
git clone https://<TOKEN>@github.com/DrdotHouse2106/SW6-Githubupdater.git DhGithubUpdater
git -C DhGithubUpdater remote set-url origin https://github.com/DrdotHouse2106/SW6-Githubupdater.git
```

oder auf GitHub *Code → Download ZIP* wählen, entpacken und den Ordner als
`custom/plugins/DhGithubUpdater` hochladen. Danach wie unter [Installation](#installation)
beschrieben fortfahren.

## Nutzung

1. **Installieren**: *Erweiterungen → Meine Erweiterungen → „Aus GitHub installieren“*,
   z. B. `https://github.com/FriendsOfShopware/FroshTools` eingeben → „Versionen laden“
   → Version wählen → „Installieren“.
2. **Repository verknüpfen** (für bereits vorhandene Plugins): Auf der Plugin-Karte
   „Repository verknüpfen“ klicken und die GitHub-URL speichern.
3. **Aktualisieren**: Auf der Plugin-Karte „Updates prüfen“ → Version wählen → „Aktualisieren“.

## Einschränkungen

- Unterstützt werden Repositories, in deren Hauptverzeichnis die `composer.json` des
  Plugins liegt (`type: shopware-platform-plugin`).
- Plugins, die zusätzliche Composer-Abhängigkeiten in `vendor/` mitbringen müssen oder
  gebaute Assets nur in Release-Archiven ausliefern, funktionieren aus dem Quellcode-Archiv
  ggf. nicht vollständig.
- Archive mit symbolischen Links werden aus Sicherheitsgründen abgelehnt.
- Per Composer (`vendor/`) installierte Plugins werden nicht angefasst.

## API-Endpunkte

Alle unter `/api/_action/dh-github-updater/` (Admin-API, OAuth):

| Methode | Pfad | ACL |
|---|---|---|
| GET | `versions?repository=<url>` | `system.plugin_maintain` |
| GET | `sources` | `system.plugin_maintain` |
| GET | `check/{pluginName}` | `system.plugin_maintain` |
| POST | `install` (`repository`, `ref`, `type`) | `system.plugin_maintain`, `system.plugin_upload` |
| POST | `update/{pluginName}` (`ref`, `type`) | `system.plugin_maintain`, `system.plugin_upload` |
| POST | `link/{pluginName}` (`repository`, leer = entfernen) | `system.plugin_maintain`, `system.plugin_upload` |

## Entwicklung

```bash
composer install
vendor/bin/phpunit
```

Die Unit-Tests decken u. a. Zip-Slip, Symlinks, Zip-Bomben, URL-Validierung,
Redirect-Schutz, Token-Weitergabe und Pfad-Prüfungen ab.

## Lizenz

MIT – siehe [LICENSE](LICENSE).
