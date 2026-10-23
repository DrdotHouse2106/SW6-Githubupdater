# 1.2.3
- Hinweise nach dem Neuladen erscheinen in der Sprache der Administration (vorher teils Englisch)
- Zeitüberschreitung beim Aktivieren: Ist das Plugin trotzdem aktiv, kommt eine Erfolgsmeldung statt einer Warnung
- Hinweis zu Composer-Paketen ist jetzt eine Info und erklärt, dass Shopware sie meist selbst installiert

# 1.2.2
- Update-Prüfung braucht deutlich weniger GitHub-Anfragen: Es werden nur Versionen geprüft, die neuer als die installierte sind (vorher bis zu 15 je Plugin) – wichtig ohne Token (60 Anfragen pro Stunde)
- Meldung beim GitHub-Limit nennt die Uhrzeit, ab der es wieder geht, und den Hinweis auf das Token

# 1.2.1
- Scheitert nach dem Herunterladen nur das Installieren/Aktivieren durch Shopware (z. B. Zeitüberschreitung beim Kompilieren der Themes), erscheint ein genauer Hinweis statt „Unbekannter Fehler“; die Seite wird trotzdem neu geladen, damit das Plugin in der Liste steht
- Fehlermeldungen ohne Text zeigen den HTTP-Status bzw. „Timeout“

# 1.2.0
- Neu: Plugin-Katalog („Plugin-Katalog“ in Meine Erweiterungen) mit Suche, Kategorien und Status je Plugin; Installieren bzw. Verknüpfen mit einem Klick. Katalog wird alle 6 Stunden von GitHub aktualisiert, Fallback auf mitgelieferte Fassung
- Neu: Eigene Plugins beitragen per Pull Request oder Issue-Formular („Plugin vorschlagen“), CONTRIBUTING.md
- Neu: Fertige Release-Pakete (ZIP-Anhang am GitHub-Release) werden bevorzugt installiert – mit gebauter Administration und `vendor/`; Fallback auf Quellcode
- Neu: Automatische Update-Prüfung alle 12 Stunden (geplante Aufgabe), Updates direkt auf der Plugin-Karte, einmalige Benachrichtigung je neuer Version
- Neu: Hinweise nach Installation/Update bei fehlender gebauter Administration/Storefront oder fehlenden Composer-Paketen
- Neue Admin-API-Endpunkte `catalog` und `update-status`

# 1.1.1
- Meldungen zeigen wieder den Plugin-Namen an (Platzhalter gingen verloren)
- „Updates prüfen“ unterscheidet jetzt zwischen „auf dem neuesten Stand“ (mit neuester Version) und „keine passende Version/kein Release gefunden“

# 1.1.0

- Plugin-Karten überarbeitet: Der GitHub-Bereich erscheint nur noch bei verknüpften Plugins
  und ist kompakt (GitHub-Symbol, Repository-Link, installierte Version, Update-Hinweis).
- Verknüpfen, Bearbeiten und Entfernen der GitHub-Verknüpfung über das „…“-Menü der
  Plugin-Karte – für alle installierten Plugins, inkl. Store-Plugins – mit eigenem Dialog
  und URL-Prüfung.
- Neu: „Verbindung testen“ (Dialog und „…“-Menü). Prüft serverseitig und nur lesend
  Eingabe, erlaubte Repositories, Zugriff/Token/Ratenlimit, composer.json und
  Plugin-Zuordnung, Releases sowie kompatible Versionen für die laufende Shopware-Version.
  Neuer Admin-API-Endpunkt `GET /api/_action/dh-github-updater/test-connection`.
- README: „Häufige Probleme und Lösungen“ als Abschnitte statt breiter Tabelle.

# 1.0.0

- Erste Version: Installation, Update-Prüfung und Update von Plugins aus GitHub mit
  Sicherheitsmaßnahmen gegen Shell-Injection, Zip-Slip, Path-Traversal, SSRF und Zip-Bomben.
