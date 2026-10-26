# Crownd – Anleitung

## 1. Einrichten unter https://rhcd.abitar.ch/mvp

Crownd ersetzt den bisherigen Redirect auf `/mvp`. Der Link (und damit ein bereits gedruckter QR-Code) bleibt gleich.

1. Release-Datei `crownd-<version>.zip` unter **Releases** herunterladen (nicht «Source code»).
2. Eine MySQL/MariaDB-Datenbank bereitstellen. Eine bestehende geht auch: Alle Tabellen von Crownd beginnen mit `crownd_` und kommen anderen Anwendungen nicht in die Quere.
3. **Alten Redirect entfernen:** In der `.htaccess` im Hauptordner von `rhcd.abitar.ch` die Regel löschen, die `/mvp` auf `mvp.php` umleitet, und `mvp.php` (samt `api/` und `data/` des Redirect-Tools) entfernen oder umbenennen. Sonst landet `/mvp` weiterhin beim Redirect.
4. Den Ordner `crownd` aus dem ZIP per FTP als Ordner **`mvp`** in den Hauptordner hochladen. Ergebnis: `…/mvp/public`, `…/mvp/src`, `…/mvp/.htaccess` usw.
5. Die Ordner `mvp/config` und `mvp/storage` (mit Unterordnern) beschreibbar machen.
6. https://rhcd.abitar.ch/mvp öffnen – der Installer startet. Datenbank-Zugang, Vereinsname und deinen Admin-Zugang eintragen. **Gleich nach dem Hochladen ausführen.**

## 2. Vorbereiten (einmalig)

1. **Einstellungen:** Vereinslogo, Vereinsfarbe und den Namen der Auszeichnung («Player of the Match» oder «MVP»).
2. **Mannschaften:** Eigene Mannschaft und die Gegner anlegen, je mit Logo.
3. **Spieler:** Pro Mannschaft einzeln mit Foto oder als Liste einfügen (`12 Nico Muster`, eine Zeile pro Spieler). Das Foto ist freiwillig.
4. **Vorlagen:** Bei der eigenen Mannschaft eine Vorlage wie «NLA» erstellen und abhaken, wer dazugehört.
5. **Plakat:** Unter Übersicht › *Plakat drucken* den QR-Code ausdrucken und aufhängen. Er gilt für alle Spiele.

## 3. Am Spieltag

1. **Spiele › Neues Spiel:** Heim- und Gastmannschaft wählen, bei der eigenen Mannschaft die Vorlage (z. B. NLA).
2. **Aufstellung prüfen:** Wer heute nicht spielt, abhaken. Fehlen Spieler des Gegners, unter *Spieler ergänzen* vom Matchblatt einfügen.
3. **In der Pause:** «Abstimmung starten» – in der Übersicht oder direkt auf https://rhcd.abitar.ch/mvp, wenn du als Admin angemeldet bist. Optional endet die Abstimmung automatisch nach einer eingestellten Zahl Minuten.
4. **Während der Abstimmung:** Unter *Auswertung* siehst du live, wer vorne liegt. Die Zuschauer sehen das Resultat nicht.
5. **Nach dem Spiel:** «Abstimmung beenden». Mit *Beamer-Ansicht* das Resultat gross zeigen, mit *Resultat freigeben* auch auf der Abstimmungsseite.

## Gut zu wissen

- Pro Gerät ist pro Spiel eine Stimme möglich. Wer zwei Geräte hat, kann zweimal abstimmen – für ein Vereinsvoting genügt das in aller Regel.
- Ein Testlauf vor dem Spiel? Abstimmung starten, abstimmen, beenden und unter *Weitere Aktionen* «Alle Stimmen löschen». Danach lässt sie sich wieder starten.
- Versehentlich beendet? «Wieder öffnen» startet die Abstimmung erneut, die bisherigen Stimmen bleiben.
- Update: neues ZIP hochladen, dabei `config/config.php` und `storage/uploads/` behalten.

## Neue Version veröffentlichen

1. Im Pull Request die Version in `src/App.php` erhöhen (`VERSION = '1.1.0'`).
2. In `CHANGELOG.md` einen Abschnitt `## [1.1.0] – 2026-11` mit den Änderungen ergänzen – das werden die Release-Notes.
3. Pull Request mergen. GitHub erstellt danach automatisch das Release `v1.1.0` mit `crownd-1.1.0.zip` (Actions › Release).

Zum Testen vor dem Mergen: Im Pull Request unter *Checks* › *CI* › *Artifacts* liegt das ZIP `crownd-<version>-pr<nummer>.zip`.
