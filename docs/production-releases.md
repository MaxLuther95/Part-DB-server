# Releases der Fertigungserweiterung

## Branches und Versionsnummern

- `master` bleibt die Referenz zum ursprünglichen Part-DB-Projekt.
- `ProjectManager` enthält die laufende Entwicklung der Erweiterung.
- `main` enthält geprüfte Stände. Änderungen werden über Pull Requests übernommen; der Status `Release checks` muss erfolgreich sein.
- Ein Tag wie `production-v1.2.3` veröffentlicht die eigene Erweiterungsversion. Diese Nummer ist unabhängig von der zugrunde liegenden Part-DB-Version. Major steht für inkompatible Änderungen, Minor für kompatible Erweiterungen, Patch für kompatible Fehlerkorrekturen.

Der Release-Workflow akzeptiert ausschließlich `production-vMAJOR.MINOR.PATCH`, ohne führende Nullen oder Vorabversionszusätze. Der Commit muss auf `main` enthalten sein. Alle Tests laufen für genau diesen Commit erneut. Ein Fehler, ein abgebrochener oder übersprungener Pflichtcheck verhindert die Veröffentlichung.

## Workflow-Dateien

- `.github/workflows/ci.yml`: PHPUnit-Matrix, statische Analyse, MariaDB-Upgrade und gemeinsamer Pflichtstatus.
- `.github/workflows/tests.yml`: PHP 8.2 bis 8.5 auf MySQL, PostgreSQL und SQLite.
- `.github/workflows/upgrade_test.yml`: offizielles Image `jbtronics/part-db1:v2.12.3` auf MariaDB 12.3.3, synthetischer Bestand, unterbrochene Indexmigration, Upgrade bis zum aktuellen Stand und komplette Testsuite.
- `.github/workflows/release.yml`: prüft Tag, Commit und unverbrauchte Image-Versionen; ruft CI und anschließend den Image-Build auf.
- `.github/workflows/docker_build.yml`: gemeinsamer Build für Apache und FrankenPHP, jeweils amd64 und arm64. Er ersetzt die beiden unabhängig publizierenden Workflows.

Es entstehen ausschließlich feste Image-Tags:

```text
ghcr.io/maxluther95/part-db-server:1.2.3
ghcr.io/maxluther95/part-db-server-frankenphp:1.2.3
```

Die bisherigen Tags `current`, `edge` und Branch-Tags werden durch diesen Ablauf nicht mehr aktualisiert. Es gibt auch keine beweglichen Major-/Minor-/Latest-Aliase. Die Synology-Konfiguration muss ausdrücklich auf eine veröffentlichte Version oder deren Digest umgestellt werden; ein alter `current`-Eintrag bleibt sonst beim bisherigen Image.

## Eine Version veröffentlichen

1. Die gewünschten Änderungen aus `ProjectManager` per Pull Request nach `main` übernehmen. Den erfolgreichen `Release checks`-Status des endgültigen Commits abwarten.
2. Für genau diesen Commit einen neuen annotierten Tag setzen und nur diesen Tag pushen, beispielsweise:

   ```sh
   git fetch origin main
   git tag -a production-v1.2.3 origin/main -m "Release production extension 1.2.3"
   git push origin refs/tags/production-v1.2.3
   ```

3. Den erfolgreichen Workflow `Publish production images` abwarten. In GHCR müssen Architektur, Versionslabel und Commit-Revision zum gewählten Stand passen.
4. Vor dem Update Datenbank und Uploads lokal sichern. Auf der Synology das feste Apache-Image eintragen, herunterladen und den Anwendungscontainer neu erstellen. Eine Datenbankmigration muss erfolgreich beendet sein, bevor die Fertigung verwendet wird.

Ein bereits veröffentlichter Image-Tag wird nicht überschrieben. GitHub-Regelsätze verhindern Änderung und Löschung der `production-v*`-Tags. Der Registry-Check bricht bei Authentifizierungs-, Netzwerk- und Serverfehlern ab. Scheitert die Veröffentlichung zwischen den beiden Image-Varianten, bleiben bereits publizierte Versionen erhalten; für einen neuen vollständigen Versuch wird eine neue Versionsnummer verwendet. Repository-/Package-Administratoren könnten Schutzregeln ändern oder Registry-Tags außerhalb dieses Workflows überschreiben; die Regeln ersetzen daher keine restriktive Vergabe der Administrationsrechte. Ein Digest bindet eine Installation zusätzlich an den konkreten Image-Inhalt.

Ein älteres Image allein ist nach einer Datenbankmigration kein verlässliches Rollback. Im Fehlerfall gehören das vorherige Image und die dazu passende lokale Datensicherung zusammen.

## Schutz der Firmendaten

CI verwendet ausschließlich synthetische Testdaten. Datenbanken, PDFs aus dem Betrieb, Medien, Uploads, lokale Umgebungsdateien und Sicherungen gehören nicht in Git. Der Image-Build erhält ein separates Verzeichnis aus `git archive` des geprüften Commits; Laufzeitdateien aus Tests oder dem lokalen Arbeitsverzeichnis können so nicht versehentlich in den Build-Kontext gelangen. Auch die zusätzlichen Quellcode-Artefakte werden aus dem Git-Archiv und explizit benannten Build-Ausgaben zusammengestellt.

Die lokalen Hooks werden mit `git config core.hooksPath .githooks` aktiviert. Vor dem Commit wird der Index geprüft; vor dem Push werden der Zielstand und neue Zwischencommits geprüft. Automatische Prüfungen erkennen bekannte Dateitypen, Laufzeitpfade und Zugangsdatenmuster, aber nicht jede vertrauliche Information in beliebigem Quelltext. Deshalb bleibt die Sichtprüfung der tatsächlich veröffentlichten Änderungen erforderlich. Ein CI-Check allein kommt für bereits gepushte vertrauliche Dateien zu spät.

Diese Schutzmaßnahmen entfernen keine früher veröffentlichten Git-Objekte aus GitHub-Caches oder dem Fork-Netzwerk; deren Entfernung bleibt ein separates Support-Thema.

## Wiederaufnahme der Indexmigration

`Version20260901080000` ergänzt fehlende Produktionsindizes und akzeptiert bereits vorhandene Indizes nur bei passender Definition. Ein gleichnamiger Index mit anderen Spalten, anderer Reihenfolge, Eindeutigkeit oder Einschränkungen führt zu einem gezielten Abbruch. Es werden weder Indizes pauschal gelöscht noch Migrationen ungeprüft als erledigt markiert.

Nach Bereitstellung eines Images mit dieser Korrektur: Datensicherung erstellen, `php bin/console doctrine:migrations:migrate -n` im Anwendungscontainer ausführen und mit `php bin/console doctrine:migrations:up-to-date -n` kontrollieren. Die synthetischen Upgrade-Tests ersetzen nicht die Prüfung eines konkreten Synology-Fehlers; weitere HTTP-500-Fehler benötigen das jeweilige Serverprotokoll.

MySQL-normalisierte JSON-Schlüssel beeinflussen die Fertigungs-Fingerabdrücke nicht mehr. Bereits offene Bauassistenten aus der früheren Ablaufversion werden mit einem Hinweis zum Neustart zurückgewiesen. Gespeicherte Auftragspositionen und Fertigungssnapshots bleiben unverändert.
