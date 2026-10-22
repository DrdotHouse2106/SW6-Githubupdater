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
