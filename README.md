# PoE2 Build Helper

Webapp zum Erstellen, Prüfen, Teilen und Exportieren von Path-of-Exile-2-Builds.
Eine Build-Skizze entsteht im Browser, ein Regelmotor prüft sie, und das Ergebnis
verlässt das Werkzeug als offizielle `.build`-Datei für den Build Planner des
Spiels.

Kein DPS- oder EHP-Simulator — das bleibt Path of Building 2.

## Stand

Entwurfsphase. Es existiert noch kein Code, nur der Entwurf unter
[`docs/specs/`](docs/specs/). Öffentlicher Start ist für Januar 2027 geplant,
kurz nach dem 1.0-Release von PoE2; entwickelt wird gegen den Early-Access-Stand
0.5.5.

## Spieldaten

Dieses Repository enthält keine Spieldaten. Passivbaum, Gems, Uniques und
Base-Items werden zur Laufzeit von den jeweiligen Quellen geholt und in einem
ignorierten Verzeichnis abgelegt. Sie gehören Grinding Gear Games; Einzelheiten
in [`NOTICE`](NOTICE).

Katalog, Editor, Regelmotor und Export sind und bleiben kostenlos. Kein aus
GGG-Daten abgeleiteter Inhalt liegt jemals hinter einer Bezahlschranke.

## Lizenz

Eigener Code unter [MIT](LICENSE). Was nicht darunter fällt, steht in
[`NOTICE`](NOTICE).

---

This product isn't affiliated with or endorsed by Grinding Gear Games in any way.
