# Fehlerprotokoll

## 2026-09-11 — "kein Node" zu weit ausgelegt
**Was:** Stack-Festlegung "kein Node" (Teil 1) als Verbot jeder Node-Abhängigkeit gelesen. Gemeint war: kein NodeJS-Backend. Playwright fälschlich als Stack-Konflikt dargestellt.
**Folge:** Eine überflüssige Rückfrage zum E2E-Werkzeug, Nutzer musste korrigieren.
**Woran erkennbar gewesen:** Doc-Zeile lautet "Assets über AssetMapper (kein Node)" — Klammer bezieht sich auf Assets, nicht auf das Projekt. Kontext der Zeile lesen statt Stichwort.
**Status:** einmalig
