# PoE2 Build Helper — Design

Stand: 2026-09-11. Teil 1 (Architektur, Datenfluss) und Teil 2 (Datenmodell,
Regelmotor, Tests, Iterationsschnitt) besprochen und bestätigt.
Offen sind nur noch die benannten Belege und der gemeinsame Durchgang durch
Warnungen und Hinweise — siehe Abschnitt „Offene Punkte".

Prosa deutsch, weil der Projektinhaber deutsch arbeitet. Bezeichner, Dateinamen,
Code-Kommentare und Commit-Botschaften bleiben englisch.

## Zweck

Webapp, die beim Erstellen von PoE2-Builds hilft: eigene Build-Skizzen bauen,
prüfen lassen, teilen und als offizielle `.build`-Datei ins Spiel exportieren.
Kein DPS-/EHP-Simulator — das bleibt Path of Building 2.

## Entschiedene Grundsatzfragen

| Frage | Entscheidung |
|---|---|
| Kernaufgabe | Build Discovery + Guided Assistant, Schwerpunkt zunächst eigener Build |
| Assistent-Motor | Stufe 1 regelbasiert. LLM in Stufe 2 mit eigenem Nutzer-Key oder gedeckeltem Betreiberbudget |
| Datenweg | MVP ohne Fremd-API-Abhängigkeit; Erweiterbarkeit auf API-Anbindung eingebaut |
| Stack | Symfony 7 als Server ab MVP, MariaDB/MySQL |
| Frontend | Twig + Symfony UX (Stimulus/Turbo), Assets über AssetMapper |
| Build-Besitz | Anonym, nicht erratbarer Share-Slug + separates Edit-Token, kein Login |
| Katalog-Speicherung | Variante C: schmale Filterspalten + JSON-Spalte je Datensatz |
| MVP-Umfang | Katalog, Build-Editor, Regelmotor, `.build` Ex- und Import |
| Veröffentlichung | Anwendungscode öffentlich unter MIT; Deploy-Konfiguration privat |
| LLM-Finanzierung | Stufe 2: eigener Nutzer-Key und geteiltes Monatsbudget mit Deckel; bezahlte Kontingente als abgeschalteter Schalter |

Bewusst nicht im MVP: Ladder-Meta, Preise, Login, LLM, Rare-Mods/Crafting.

Zum Stack präzisiert (2026-09-11): „kein Node" meint kein NodeJS-Backend und
keine Node-Abhängigkeit in der Asset-Kette. Als Entwicklungs- und CI-Werkzeug ist
Node zulässig — die E2E-Tests laufen mit Playwright.

## Datenlage (recherchiert 2026-09-09)

| Was | Quelle | Status |
|---|---|---|
| Passiv-Baum (Nodes, IDs, Layout) | https://github.com/grindinggear/poe2-skilltree-export | offiziell |
| Build-Austauschformat | https://www.pathofexile.com/developer/docs/game#buildplanner | offiziell, Version 1 (Experimental) |
| Gems, Supports, Uniques, Base-Items, Ascendancies, Tags | https://repoe-fork.github.io/poe2/ | inoffiziell; Code MIT, Daten ausdrücklich GGG-Eigentum (siehe Abschnitt Lizenz und Rechte) |
| Preise | poe.ninja Economy, `GET /poe2/api/economy/...` | öffentlich, ohne Auth, `User-Agent`-Pflicht, 5-Minuten-Cache respektieren |
| Ladder (Rang, Name, Klasse, Level) | GGG `GET /league/<league>/ladder`, Scope `service:leagues:ladder` | Confidential Client mit `client_credentials` nötig |
| Fremde Charaktere mit Gear und Baum | — | existiert nicht |

### Belegte Einschränkungen

- Kein OAuth-Scope liefert fremde Charaktere. Alle `account:*`-Scopes gelten nur
  für den Token-Besitzer. Gear und Baum fremder Spieler sind offiziell nicht
  abrufbar.
- Alle `service:*`-Scopes erfordern einen Confidential Client. Public Clients
  (PKCE) dürfen sie nicht nutzen und teilen ihre Rate-Limits mit allen anderen
  Public Clients.
- OAuth-Redirect-URIs müssen registrierte HTTPS-Domains sein. Wörtlich:
  „We cannot accept IP addresses or localhost domains even for in-development
  projects." Lokale Entwicklung eines Logins braucht Tunnel oder Stub.
- CORS ist nirgends zugesagt; Browser-Direktzugriff auf `api.pathofexile.com`
  ist nicht vorgesehen.
- poe.ninja: „The builds / profiles API, and every other non-economy endpoint
  (character, Path of Building, authentication), are internal. They are
  undocumented, unsupported, and not available for third-party use." Nur
  Economy-Endpunkte sind nutzbar.
- Pflicht-Header der GGG-API: `User-Agent: OAuth {clientId}/{version} (contact: {contact})`.
  Rate-Limits über `X-Rate-Limit-{rule}`, `X-Rate-Limit-{rule}-State`, `Retry-After`.

### Dateigrößen RePoE-Fork (`.min.json`, Last-Modified 2026-09-07)

`mods` 8,7 MB · `ascendancies` 3,9 MB · `base_items` 3,1 MB ·
`skill_gems` 957 KB · `uniques` 115 KB · `tags` 25 KB.
`stat_translations` und `passive_skill_trees` liefern unter diesem Pfad 404 —
richtiger Pfad noch zu ermitteln.
`mods` wird im MVP nicht gebraucht (kein Rare-Crafting).

## Lizenz und Rechte (geklärt 2026-09-09)

RePoE räumt an den Daten bewusst keine Rechte ein. `LICENSE.md`, gleichlautend in
`repoe-fork/repoe` und im Original `brather1ng/RePoE`: MIT für den Code, danach

> Contents of generated files (all files in the `data` directory) are owned by
> Grinding Gear Games and shall not be used or published without being in
> accordance with their terms of use.

Das Datenrepo `repoe-fork/poe2` hat keine Lizenzdatei. Der Generator
`repoe-fork/pypoe` steht unter GPL-3.0, was den Generator deckt, nicht dessen
Ausgabe.

Damit gelten GGGs Terms of Use. Wörtlich:

> Grinding Gear Games grants you a limited licence […] for your own personal and
> non-commercial use

> Under no circumstances, without the prior written approval of Grinding Gear
> Games, may you: Adapt, reproduce, store, distribute, print, display, publish or
> create derivative works from any part of the Website, Materials or Services
> other than in accordance with the Licence.

Aus der Developer-Doku ergänzend: „We do not officially provide access to any
in-game data outside of our supported APIs.", „As a general rule, we cannot allow
our Intellectual Property to be used to generate commercial revenue." und die
Pflicht zum Hinweis „This product isn't affiliated with or endorsed by Grinding
Gear Games in any way."

Einordnung: kein Freibrief, aber auch keine Sperre — das gesamte Werkzeug-Umfeld
(Path of Building, poe2db, Wikis) arbeitet auf dieser Grundlage. Geduldet, nicht
lizenziert. Das Risiko ist Abschalt-Klasse, nicht Schadensersatz-Klasse.
Keine rechtsverbindliche Bewertung; bei kommerzieller Absicht wäre eine Anfrage
an GGG der richtige Weg.

### Verbindliche Maßnahmen

1. Kein Geld für GGG-abgeleitete Funktionen. Die LLM-Funktion läuft mit
   eigenem Nutzer-Key oder aus einem gedeckelten Betreiberbudget; bezahlte
   Kontingente erst nach Zustimmung von GGG. Siehe Abschnitt
   „Open Source, Hosting, LLM-Finanzierung".
2. Hinweis wörtlich und sichtbar im Footer: „This product isn't affiliated with
   or endorsed by Grinding Gear Games in any way."
3. Keine GGG- oder PoE-Logos; kein Name und keine Domain, die Offizielles
   suggerieren.
4. Offizielle Quellen bevorzugen (Skilltree-Export, `.build`-Format,
   Economy-API). RePoE nur für das, was offiziell fehlt: Gems, Supports,
   Uniques, Base-Items, Tags.
5. RePoE-Snapshots werden nicht committet und nicht weiter ausgeliefert. Sync
   holt Upstream zur Laufzeit, Snapshots stehen in `.gitignore`. Kein
   Bulk-Download, keine öffentliche Daten-API. Anzeigen ja, weiterverteilen nein.
6. Attribution: MIT-Hinweis für den RePoE-Code plus Satz, dass die Spieldaten
   Grinding Gear Games gehören.
7. Upstream schonen: `User-Agent` mit Kontakt, `Last-Modified`/ETag auswerten,
   nicht häufiger ziehen als nötig.
8. Katalog-Sync muss abschaltbar sein, ohne dass die App stirbt.

### Architekturfolge aus Maßnahme 8

Der Katalog darf nicht tragend sein. Ein Build muss sich öffnen, bearbeiten,
exportieren und teilen lassen, auch wenn keine Katalogdaten vorliegen — dann ohne
Namen, Icons und Regelprüfungen. Passt zu `Advice` hinter einem Port: kein
Katalog, keine Befunde, alles andere läuft weiter.

## Open Source, Hosting, LLM-Finanzierung (entschieden 2026-09-09)

### Veröffentlichung

Anwendungscode öffentlich unter **MIT**. Bewusst gewählt: MIT erlaubt Dritten
auch kommerzielles Hosting; die GGG-Datenfrage liegt dann bei diesen Dritten.

Zwei Dateien im Repo-Wurzelverzeichnis:

- `LICENSE` — MIT, eigener Code.
- `NOTICE` — hält fest, was **nicht** unter MIT steht: Spieldaten sind Eigentum
  von Grinding Gear Games und werden nicht mitgeliefert; RePoE-Code ist MIT
  (Copyright 2016 brather1ng), dessen Daten ausdrücklich nicht.

Ein öffentliches Repo verschärft Maßnahme 5: committete Snapshots wären genau
das „publish", das die Terms untersagen. Snapshots landen in einem
`.gitignore`-Pfad und werden ausschließlich vom Sync-Command erzeugt.

### Deploy-Konfiguration privat

Im öffentlichen Repo stehen nur `.env` mit unschädlichen Vorgaben und
`.env.example`. Nicht darin: `.env.local`, Hostnamen, Domains, Server- und
Container-Konfiguration, Deploy-Skripte, CI-Secrets. Diese liegen privat oder
außerhalb der Versionierung.

Prüfpunkt vor dem ersten Push: Repo nach Domain, Host, Key und Token durchsuchen.

### LLM-Finanzierung (Stufe 2, nicht MVP)

Bezahlt werden LLM-Tokens, nicht der Zugang zu GGG-Daten. Katalog, Editor,
Regelmotor und Export bleiben vollständig kostenlos. **Kein GGG-abgeleiteter
Inhalt liegt jemals hinter einer Bezahlschranke** — dieser Satz gehört in
README und Oberfläche.

Ein `LlmBudget` mit drei Betriebsarten, per Konfiguration umschaltbar:

| Art | Verhalten | Status |
|---|---|---|
| `user_key` | Nutzer trägt eigenen Anthropic-Key ein. Kein Geldfluss. | vorgesehen |
| `shared_budget` | Ein Key des Betreibers, hartes Monatslimit, Zählung pro Nutzer, damit einer das Budget nicht leerzieht. Nach Erschöpfung Hinweis auf eigenen Key. Kein Geldfluss. | vorgesehen |
| `paid_credits` | Bezahlte Kontingente zum Selbstkostenpreis. | **abgeschaltet**, bis GGG zugestimmt hat |

`paid_credits` bleibt aus, weil GGG „cannot allow our Intellectual Property to be
used to generate commercial revenue" schreibt — Umsatz, nicht Gewinn. Vor dem
Einschalten eine kurze Anfrage an GGG, die Tool und Modell beschreibt. Der Code
unterscheidet sich zwischen den drei Arten nur in der Zählung, das Einschalten
ist Konfiguration und kein Umbau.

## Das `.build`-Format als Angelpunkt

GGG-Doku, wörtlich: „Designed for players to import builds from third-party
sources, the Build Planner functions as a plug-and-play feature. Editing or
creating builds within Path Of Exile 2 is currently not supported."

Das Spiel kann Builds anzeigen, aber nicht erstellen — genau die Lücke des Tools.
Das Format ist zugleich das Datenmodell: keine Rares, keine Zahlen, nur Passives,
Skills mit Supports und Unique-Hinweise pro Inventarslot. `level_interval` macht
die Levelreihenfolge maschinenlesbar.

```
Build:              name, author?, link?, description?, ascendancy?,
                    passives[], skills[], inventory_slots[]
BuildPassive:       id ("strength89", PassiveSkills-Tabelle), level_interval?,
                    weapon_set? (0..2), additional_text?
BuildSkill:         id ("Metadata/Items/Gems/SkillGemEarthquake", BaseItemTypes),
                    level_interval?, additional_text?, support_skills[]
BuildSupport:       id ("Metadata/Items/Gems/SupportGemFastForward"),
                    level_interval?, additional_text?
BuildInventorySlot: inventory_id ("Weapon1", Inventories-Tabelle), slot_x?,
                    slot_y?, level_interval?, unique_name? ("Kalandra's Touch"),
                    additional_text?
```

`additional_text` erlaubt Markup: Schrift `<r> <b> <i> <u> <s> <m> <l>`,
Farben `<red> … <gold> <unique>` sowie `<rgb(r, g, b)>`, Form `<key>{ Text }`.

Ablage der Dateien: Windows
`C:/Users/Name/Documents/My Games/Path of Exile 2/BuildPlanner`;
SteamOS
`/home/deck/.local/share/Steam/steamapps/compatdata/2315204395/pfx/drive_c/users/steamuser/Documents/My Games/Path of Exile 2/BuildPlanner`.
Ein File Watcher des Spiels erkennt Änderungen. Alternativ Abo über
pathofexile2.com.

## Architektur (Teil 1, bestätigt)

Fünf Module, Abhängigkeiten nur nach unten:

| Modul | Aufgabe | Kennt |
|---|---|---|
| `Catalog` | Spieldaten, nur lesend. Sync-Commands, Repositories, Suche. | nichts darüber |
| `Build` | Build-Skizze als Aggregat, Persistenz, Share- und Edit-Token. | `Catalog` (nur ID-Prüfung) |
| `Interchange` | `.build`-JSON lesen und schreiben, Format v1. | `Build`, `Catalog` |
| `Advice` | Regelmotor: Skizze + Katalog → Befunde. Ohne DB, ohne HTTP. | nur Ports |
| `Ui` | Controller, Twig, Stimulus. | alle darunter |

Später ergänzbar ohne Eingriff in Bestehendes: `Meta` (Ladder-Aggregate),
`Assist` (LLM).

Tragende Festlegung: `Advice` erhält Katalogdaten über ein Interface, nicht über
Doctrine. Signatur `(BuildSketch, CatalogPort) -> Finding[]`. Damit ist der
Regelmotor mit Fake-Katalog vollständig unit-testbar — der Teil, der bei jedem
PoE2-Patch nachjustiert wird.

### Datenfluss

```
Sync (CLI, manuell)
  poe2-skilltree-export  ──┐
  repoe-fork: skill_gems,  ├─→ Normalisierer ─→ MariaDB (Katalog)
    base_items, uniques,   │                    Filterspalten + JSON-Spalte
    ascendancies, tags   ──┘

Nutzer (Browser, Turbo)
  Editor ──→ Build-Aggregat ──→ MariaDB (Builds)
                  ├──→ Advice ──→ Befunde inline im Editor
                  ├──→ Interchange ──→ Download .build
                  └──← Interchange ←── Upload .build / JSON einfügen
```

Sync bleibt im MVP ein Command ohne Automatismus: Patch-Übernahme ist eine
Entscheidung, und ein kaputter Upstream reißt die laufende App nicht mit.
Jeder Lauf schreibt Quelle, Zeitstempel und Upstream-`Last-Modified` nach
`catalog_sync`, damit der angezeigte Datenstand belegbar ist.

JSON-Pfade werden in MariaDB über *generated columns* indiziert — was indiziert
werden soll, wird zur echten Spalte. Deckt sich mit Variante C.

### Offenes Risiko, zuerst zu klären

`.build` referenziert `PassiveSkills`-IDs (`strength89`) und
`BaseItemTypes`-Pfade (`Metadata/Items/Gems/SkillGemEarthquake`). Ob
Skilltree-Export und RePoE genau diese Schlüssel führen, ist unbestätigt.
Falls nicht, braucht `Catalog` eine Mapping-Schicht. Das ist der erste
Umsetzungsschritt — daran hängt, ob der Export ins Spiel funktioniert.

## Zeitrahmen und Phasen (2026-09-11)

PoE2 ist bis Dezember 2026 im Early Access. Entwicklungs- und Teststand ist
**0.5.5**. Version 1.0 wird etwa im Dezember 2026 erwartet, der **öffentliche
Start des Werkzeugs etwa im Januar 2027**, kurz nach 1.0.

Daraus zwei Phasen:

| Phase | Zeitraum | Spielversion | Ziel |
|---|---|---|---|
| EA | September–Dezember 2026 | 0.5.5 und Folgepatches | Mechanik fertig bauen, Inhalt bewusst dünn, privat |
| 1.0 | Dezember 2026–Januar 2027 | 1.0 | Re-Sync, Belege neu, Inhalt füllen, veröffentlichen |

**Investitionsregel.** Alles, was der Versionssprung entwertet, entsteht so spät
wie möglich. Kuratiertes Wissen bleibt bis 1.0 auf Beispielumfang — gerade genug,
um `KnowledgePort`, Verdrängungsregel und Anzeige zu prüfen. Ableitungslogik,
Ports, Datenmodell und Sync entstehen sofort; sie überleben den Sprung.

**Was 1.0 voraussichtlich bricht:** Passiv-IDs und Baumlayout, Gem-Pfade,
Support-Tag-Anforderungen, Spirit-Werte, Punktebudgets — und möglicherweise das
`.build`-Format selbst, das GGG ausdrücklich als „Version 1 (Experimental)"
führt. Kein Entwurfsteil darf annehmen, dass Katalogdaten über den Sprung stabil
bleiben.

**Altbuilds.** Ein Build behält seine `game_version`. Wird er mit einem neueren
Katalog geöffnet, wird sein `document`-JSON **nicht** still umgeschrieben;
unbekannte IDs erscheinen als Befunde. Vor dem öffentlichen Start existieren nur
eigene Testbuilds — das ist der günstigste Moment für einen harten Schnitt, falls
sich eine Abbildung 0.5.5 → 1.0 nicht lohnt. Ein Mapping-Command wäre Kür.

## Stehende Regel: PoE2-Bindung

Jede Regel, jedes Feld, jede Prüfung nennt die PoE2-Mechanik, auf der sie ruht.
Kein Übertrag aus PoE1 ohne Beleg. Wo eine Mechanik nicht aus Katalogdaten
belegbar ist, wird sie nicht abgeleitet, sondern kuratiert — oder sie entfällt.

Ebenso gilt: PoE2 ändert Mechaniken zwischen Patches. „Stimmt für PoE2" ist keine
haltbare Aussage, nur „stimmt für Version X". Deshalb tragen Regeln und
kuratierte Einträge Gültigkeitsspannen (siehe Regelmotor), und Belege tragen
einen Versionsstempel.

## Datenmodell (Teil 2, bestätigt)

### Katalog

Variante C aus Teil 1: schmale Filterspalten plus eine JSON-Spalte je Datensatz.
Listenwerte liegen in Beitabellen statt in JSON-Arrays, weil MariaDB Arrays nicht
sinnvoll indiziert.

| Tabelle | Filterspalten | JSON |
|---|---|---|
| `catalog_gem` | `id` (PK, `Metadata/Items/Gems/…`), `kind` (`active`\|`support`), `name`, `primary_attribute`, `required_level` | Stats, Beschreibung, Icon-Verweis |
| `catalog_gem_tag` | `gem_id`, `tag` | — |
| `catalog_gem_tag_requirement` | `support_id`, `tag`, `mode` (`requires`\|`excludes`) | — |
| `catalog_passive` | `id` (`strength89`), `name`, `kind` (`small`\|`notable`\|`keystone`\|`ascendancy`), `ascendancy_key?`, `pos_x`, `pos_y` | Stats, Nachbarn |
| `catalog_passive_edge` | `from_id`, `to_id` | — |
| `catalog_base_item` | `id`, `name`, `item_class`, `inventory_id` | Anforderungen, Implicit |
| `catalog_unique` | `id`, `name`, `base_item_id` | Mods als Text |
| `catalog_sync` | `source`, `ran_at`, `upstream_last_modified`, `game_version`, `status`, `count` | Fehlertext |

Tragend für den Regelmotor sind genau zwei dieser Tabellen:
`catalog_passive_edge` für den Baum-Zusammenhang und
`catalog_gem_tag_requirement` für die Support-Passung. Alles Übrige dient der
Anzeige.

### Build

Ein Wurzelsatz mit Planungsspalten; der Inhalt liegt als **ein** JSON-Dokument in
der Form des `.build`-Formats:

```
build: id, share_slug (unique), edit_token_hash, name, author?, link?,
       description?, ascendancy_key?, target_level?, game_version,
       note?, archetype_key?, created_at, updated_at,
       document JSON  -- passives[], skills[], inventory_slots[]
```

Begründung: das Aggregat wird immer ganz gelesen und ganz geschrieben, und im MVP
gibt es keine Abfrage der Art „welche Builds nutzen Skill X". Kindtabellen
brächten drei Tabellen, Sortierpflege und Import-Reihenfolge ohne Gegenwert.
Kommt die Abfrage später, wird sie eine generated column.

`edit_token` liegt nur gehasht vor, `share_slug` ist nicht erratbar — beides aus
Teil 1. `game_version` ist ein echtes Feld, kein Etikett: Vorgabe ist die Version
des letzten Katalog-Syncs, der Nutzer kann sie ändern.

### Kuratiertes Wissen

Nicht in der Datenbank, sondern versioniert im Repository unter
`config/knowledge/`. Es ist eigener Text und kein GGG-Datenexport, darf also
committet werden — anders als Katalog-Snapshots.

```
archetypes.yaml    key, name, since, until, erwartete Bausteine
                   (Tag-Muster, Stat-Schlüsselwörter, Pflicht-Keystones)
interactions.yaml  key, since, until, unique?, skill?/tag?,
                   kind (enables|modifies|forbids|synergy),
                   severity, text, quelle
```

Interaktionen zwischen Uniques und Skills sind ein eigener Eintragstyp neben den
Archetypen — sie sind der Teil, den keine Ableitung aus Tags je finden würde.

`Advice` sieht Katalog und Wissen ausschließlich über `CatalogPort` und
`KnowledgePort`. Kein Doctrine, kein Dateizugriff im Motor.

## Regelmotor (Teil 2, bestätigt)

### Bauform

Signatur: `(BuildSketch, CatalogPort, KnowledgePort) -> Finding[]`.

Jede Regel ist eine eigene Klasse hinter einem `Rule`-Interface, über Service-Tag
registriert, ohne Zustand und ohne Kenntnis der anderen Regeln. Der Motor sammelt
ein und sortiert stabil nach Schweregrad und Fundstelle.

```
Finding: rule (Regel-ID), severity (error|warning|hint),
         target (passive:<id> | skill:<idx> | support:<idx>.<idx>
                 | slot:<inventory_id> | build),
         message, origin (derived | curated:<key>),
         source_note?  (Quellentext aus der YAML),
         catalog_state (Zeitstempel und Version aus catalog_sync),
         fix?  (Patch auf die Skizze, für den Übernehmen-Knopf)
```

`origin` und `catalog_state` sind der Herkunftsnachweis: der Nutzer sieht, ob ein
Befund aus Spieldaten folgt oder aus gepflegter Meinung, und auf welchem
Datenstand er beruht.

**Kuratiert schlägt abgeleitet.** Trägt ein kuratierter Eintrag dieselbe
Fundstelle wie ein abgeleiteter Vorschlag, verdrängt er ihn. Der Schweregrad
kuratierter Einträge steht in der YAML, nicht im Code — Nachjustieren nach einem
Patch ist dann Datenpflege statt Auslieferung von Logik.

**Ohne Katalog** liefert der Motor keine Befunde, sondern genau einen:
`catalog.unavailable` als Hinweis. Das ist Maßnahme 8 aus Teil 1, im Motor
sichtbar gemacht.

### Versionsbindung

Das `Rule`-Interface trägt `since` und `until` als Spielversion (nach oben offen
zulässig); der Motor filtert vor dem Lauf gegen die `game_version` des Builds.
Dieselben zwei Felder stehen in `archetypes.yaml` und `interactions.yaml`, damit
auch kuratiertes Wissen veralten kann, ohne falsch zu werden.

Weichen Build-Version und Katalog-Version voneinander ab, ist das selbst ein
Befund — ein Hinweis, kein Fehler: die Prüfung lief gegen andere Daten als die,
für die der Build gedacht ist.

Beispielfall: Ein Support-Gem durfte in 0.1 und 0.2 nur einmal pro Charakter
stecken; seit 0.3 ist das aufgehoben. Die Regel `support.used_twice_in_build`
bleibt deshalb im Katalog, aber mit `until: 0.2`.

### Regelkatalog (Stand 0.5.5)

Fehler — mechanisch kaputt:

- `passive.unknown`, `gem.unknown`, `unique.unknown`, `slot.unknown` — ID nicht
  im Katalog
- `passive.disconnected` — Knoten hängt nicht am Klassenstart, geprüft über
  `catalog_passive_edge`
- `passive.budget_exceeded` — mehr Punkte gesetzt, als Ziellevel plus
  Questpunkte hergibt (Toleranz nötig, siehe Offene Punkte)
- `ascendancy.mismatch` — Ascendancy-Knoten ohne oder mit fremder Ascendancy
- `ascendancy.budget_exceeded`
- `support.tag_mismatch` — Support fordert einen Tag, den der Skill nicht trägt,
  oder schließt einen aus
- `support.socket_limit` — mehr Supports als Sockel
- `unique.slot_mismatch` — Unique passt nicht in diesen Inventarslot
- `level_interval.invalid` — Ende vor Anfang oder außerhalb 1–100
- `support.used_twice_in_build` — nur bis 0.2, siehe Versionsbindung

Warnungen — Lücken und Unsicheres:

- `attributes.unmet` — Attribute aus Baum und Level decken eine Gem-Anforderung
  nicht. Bewusst Warnung: Ausrüstung ist nicht modelliert und kann die Lücke
  schließen
- `spirit.overcommitted` — Spirit ist die PoE2-Ressource für dauerhafte Buffs,
  Auren und Minions. Warnung statt Fehler, weil der größte Teil aus Gegenständen
  und Ascendancy kommt, die das Format nicht trägt
- `weapon.mismatch` — Skills sind in PoE2 eng an Waffengattungen gebunden
  (Armbrust, Speer, Stab). Die stärkste ableitbare Warnung
- `weapon_set.*` — das Format führt `weapon_set` 0..2, weil PoE2 Passivpunkte
  waffensatzspezifisch vergeben lässt. Prüfungen auf ungültige oder ungenutzte
  Satz-Zuordnung
- `archetype.missing_block` — kuratiert, greift nur bei gesetztem Archetyp
- `interaction.forbidden` — kuratiert, `kind: forbids`

Hinweise — Vorschläge:

- `support.suggestion` — abgeleitet: Supports, deren Tag-Anforderungen der Skill
  erfüllt und die noch frei sind
- `passive.suggestion` — abgeleitet: Knoten in kurzer Baumdistanz mit gesuchtem
  Stat-Schlüsselwort
- `interaction.synergy` — kuratiert: Unique ↔ Skill, `kind: enables|modifies|synergy`

### Bewusst gestrichen (PoE1-Denken)

- `mobility.no_movement_skill` — PoE2 gibt jedem Charakter den Dodge Roll.
  Bewegungs-Skills sind Ausnahme, nicht Pflichtbaustein.
- `defense.no_resistances` — Resistenzen kommen in PoE2 praktisch aus
  Gegenständen; der Baum trägt sie kaum. Ihre Abwesenheit im Baum ist kein
  Befund.
- `defense.no_life_or_es` — dasselbe Muster.

Verteidigung ist über `.build` grundsätzlich nicht prüfbar, weil das Format keine
Rares kennt. Sie wandert vollständig in den kuratierten Teil, als
Archetyp-Erwartung, nicht als abgeleitete Regel.

## Nutzerführung

Kein Login, kein Konto. Einstieg über zwei Wege: neuen Build anlegen oder
vorhandene `.build`-Datei hochladen beziehungsweise JSON einfügen.

Der Editor hat vier Bereiche:

1. **Kopf** — Name, Klasse, Ascendancy, Ziellevel, `game_version`, Notiz,
   optional Archetyp. Der Archetyp ist der Schalter, der die kuratierten
   Prüfungen scharf macht.
2. **Passivbaum** — Knoten suchen und setzen, pro Knoten optional ein
   `level_interval`.
3. **Skills** — Haupt- und Nebenskills mit ihren Supports, Level-Intervall und
   freiem Zusatztext.
4. **Ausrüstungsslots** — je Slot optional ein Unique beim Namen. Keine Rares,
   keine Zahlen — genau der Umfang des Formats.

Daneben steht dauerhaft die **Befundliste**, die sich bei jeder Änderung über
Turbo neu füllt. Drei Schweregrade; jeder Befund ist anklickbar und springt an
seine Fundstelle; jeder zeigt seine Herkunft. Vorschläge tragen einen
Übernehmen-Knopf, der die Skizze direkt ändert.

Eine eigene **Katalog-Ansicht** dient dem Stöbern und Suchen in Gems, Supports,
Uniques und Baumknoten; von dort lassen sich Dinge in den offenen Build
übernehmen.

Zum Abschluss: **Teilen** liefert einen Nur-Lesen-Link und getrennt davon den
Bearbeiten-Link. **Export** lädt die `.build`-Datei herunter, mit dem Ablagepfad
für Windows und SteamOS daneben.

Ohne Katalogdaten bleibt all das benutzbar — nur Namen, Icons und Befunde fehlen.
Die Fußzeile trägt den GGG-Hinweis.

## Testansatz (Teil 2, bestätigt)

Grundsatz: TDD, Test vor Code. Der Schwerpunkt liegt dort, wo jeder Patch
nachjustiert — im Regelmotor.

**Unit, `Advice`.** Eine Testklasse je Regel gegen Fake-`CatalogPort` und
Fake-`KnowledgePort` im Speicher, ohne Datenbank und ohne Kernel. Geprüft wird
die Identität des Befunds — Regel-ID, Schweregrad, Fundstelle —, **nicht der
Meldungstext**: Texte werden umformuliert und später übersetzt, Tests dürfen
daran nicht zerbrechen. Die Versionsbindung bekommt eigene Tests: dieselbe
Skizze, zwei `game_version`-Werte, unterschiedliche Befundmengen.

**Unit, `Interchange`.** Reine Abbildungstests Dokument ↔ Aggregat, ohne
Datenbank.

**Vertragstests `.build`.** Ein Korpus selbst geschriebener Dateien unter
`tests/fixtures/build/`, gültige und kaputte. Zugesicherte Eigenschaft: Import →
Export → Import ist ab dem zweiten Durchgang stabil. Fehlerfälle — kaputtes JSON,
fehlende Pflichtfelder, falsche Typen, unbekannte Felder — liefern typisierte
Fehler, nie durchschlagende Exceptions. Dazu ein Test, dass die Ausgabe nur
Felder enthält, die die GGG-Doku für Version 1 führt.

**Functional (Symfony, echte MariaDB in CI).** Die Controller-Wege: anlegen, über
Share-Slug öffnen, exportieren, hochladen, Katalog suchen. Eigene, ausdrückliche
Tests für das Edit-Token — fehlend, falsch, richtig —, weil dort ein Fehler still
Daten preisgibt. Keine SQLite-Abkürzung: JSON- und generated columns verhalten
sich anders als in MariaDB, ein grüner SQLite-Lauf wäre wertlos.

**E2E (Playwright), bewusst klein.** Nur Wege, die ohne JavaScript nicht prüfbar
sind, Zielgröße etwa sechs: Knoten setzen und Befund erscheint; Vorschlag
übernehmen und Skizze ändert sich; Export lädt eine Datei; Upload füllt den
Editor; Lese-Link zeigt keine Bearbeiten-Elemente. Vorbedingungen setzt ein
Konsolen-Command, das einen Build samt Token anlegt und nur bei `APP_ENV=test`
existiert.

**Katalog-Sync — hier greift die Lizenz in den Testaufbau.** Echte Auszüge aus
RePoE oder dem Skilltree-Export dürfen nicht im Repository liegen; das wäre genau
das Weiterverteilen, das Maßnahme 5 untersagt. Der Normalisierer wird deshalb
gegen **synthetische** Dateien getestet, die die Form des Upstreams nachbilden,
nicht dessen Inhalt. Daneben genau ein Test, der wirklich gegen Upstream zieht —
standardmäßig übersprungen, von Hand gestartet, Teil der Versionsabnahme. Er
prüft nicht Inhalte, sondern ob die Form noch stimmt.

**Gate:** PHPUnit, PHPStan auf höchster Stufe, Coding-Standard-Prüfung,
Playwright. In CI läuft alles außer dem Upstream-Test.

## Iterationsschnitt (Teil 2, bestätigt)

Drei Prinzipien bestimmen die Reihenfolge: das benannte Risiko zuerst, jede
Iteration endet auf etwas Benutzbarem, und alles, was der 1.0-Sprung entwertet,
entsteht so spät wie möglich.

### Phase EA (September–Dezember 2026, gegen 0.5.5, privat)

**Schritt 0 — Spike Schlüssel-Mapping.** Zeitlich begrenzt, Ergebnis ist eine
Antwort und kein Code. Führen Skilltree-Export und RePoE genau die Schlüssel, die
`.build` referenziert — `strength89`, `Metadata/Items/Gems/…`, die
`Inventories`-IDs? Nebenbei die 404-Pfade für `stat_translations` und
`passive_skill_trees` klären. Der Ausgang entscheidet, ob `Catalog` eine
Abbildungsschicht braucht.

**Iteration 1 — Round-Trip.** Symfony-Gerüst, Modul `Build`, Modul `Interchange`,
Upload und Einfügen, Ablage, unveränderter Download, Share-Slug und Edit-Token,
Nur-Lesen-Ansicht. Kein Katalog, keine Regeln, kein Editor. Abnahme ist nicht ein
grüner Test, sondern: eine vom Werkzeug erzeugte Datei wird von PoE2 eingelesen.

**Iteration 2 — Katalog.** Sync-Commands, Normalisierer, Tabellen,
`catalog_sync`-Protokoll, `CatalogPort`, Such- und Stöberansicht. Ab hier tragen
Builds Namen und Icons statt IDs.

**Iteration 3 — Editor.** Bearbeiten über Turbo und Stimulus: Skills, Supports,
Slots, Level-Intervalle, Planungsfelder. Der Passivbaum kommt zunächst als
durchsuchbare Knotenliste, nicht als Grafik — die grafische Darstellung ist ein
eigener großer Brocken, und ihre Layoutdaten sind genau das, was 1.0 umwirft.

**Iteration 4 — Regelmotor, abgeleiteter Teil.** `Advice`, `Rule` mit
Versionsfiltern, die Fehler- und Warnregeln, Befundleiste, Sprung zur Fundstelle,
Herkunftsanzeige. `KnowledgePort` existiert, ist aber nur beispielhaft gefüllt.

**Iteration 5 — Vorschläge und Wissensgerüst.** `support.suggestion`,
`passive.suggestion`, Übernehmen-Knopf, kuratierte Interaktionen mit einer
Handvoll Einträgen. Inhalt bleibt absichtlich dünn.

### Phase 1.0 (Dezember 2026–Januar 2027, öffentlich)

**Iteration 6 — Versionsabnahme 1.0.** Re-Sync, die Belege neu gestempelt,
Durchgang durch den Regelkatalog, Prüfung ob `.build` formatstabil blieb,
Entscheidung über Testbuilds aus der EA-Zeit — voraussichtlich harter Schnitt.

**Iteration 7 — Inhalt.** Archetypen und Interaktionen für 1.0 füllen, dazu der
vollständige gemeinsame Durchgang durch Warnungen und Hinweise.

**Iteration 8 — Startfähigkeit.** Grafischer Passivbaum, sofern das 1.0-Layout
steht; Feinschliff; Secret-Scan; `LICENSE` und `NOTICE`; Footer-Hinweis;
`paid_credits` aus; öffentliches Repository; Deploy.

Nicht enthalten und für diesen Bogen nicht geplant: LLM, Ladder-Meta, Preise,
Rares und Crafting, Login.

## Offene Punkte

### Belege, je Spielversion zu stempeln

Gehören nach `docs/version-acceptance.md`; erster Durchlauf 0.5.5, zweiter 1.0.
Ein Beleg ohne Versionsstempel ist keiner — er verfällt beim nächsten Patch
unbemerkt.

1. Passivpunkte: Punkte pro Level plus Questpunkte, exakte Zahl
2. Ascendancy-Punkte: Anzahl und Herkunft (Trials)
3. Support-Sockel je Skill-Gem: wovon die Zahl abhängt
4. Spirit-Quellen: was davon am Baum liegt, was nur an Gegenständen
5. Waffenbindung der Skills: ob RePoE sie als Tag oder als Anforderung führt
6. `weapon_set`-Semantik des `.build`-Formats: was 0, 1 und 2 genau bedeuten

Erledigt: Einmaligkeit der Supports pro Charakter — galt bis 0.2, seit 0.3
aufgehoben.

### Notizen für den späteren Regel-Durchgang

- `passive.budget_exceeded` lässt sich legitim überschreiten: über eine Rune und
  über die Liga-Mechanik „Martyr of the First Edict", die allen Spielern einer
  Liga einen zusätzlichen Punkt gibt. Die Regel braucht eine Toleranz oder ein
  Nutzerfeld für Zusatzpunkte, sonst meldet sie bei korrekten Builds falsch.
- Vollständiger gemeinsamer Durchgang durch Warnungen und Hinweise, nach dem
  ersten Sync und gegen eine konkrete Version.

### Technisch offen

- RePoE-Pfade für `stat_translations` und `passive_skill_trees` liefern 404; der
  richtige Pfad ist zu ermitteln (Teil von Schritt 0).
- Ob `.build` den 1.0-Sprung formatstabil übersteht, ist unbekannt.

### Vor dem öffentlichen Start (Januar 2027)

Secret-Scan des gesamten Repositorys, `LICENSE` und `NOTICE` vorhanden,
Footer-Hinweis sichtbar, `paid_credits` abgeschaltet.
