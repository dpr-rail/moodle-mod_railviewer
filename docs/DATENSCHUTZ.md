# Datenschutz-Guide – mod_railviewer

**Stand:** 2026-09-15 · **Plugin-Version:** siehe `version.php` (`2026091300`, Release 0.2)
**Zielgruppe:** Moodle-/Totara-Administrator:innen, Datenschutzbeauftragte, Entwickler:innen

> Dieses Dokument ersetzt keine Rechtsberatung. Es beschreibt technisch, welche
> personenbezogenen Daten das Plugin verarbeitet, welche externen Dienste/Bibliotheken beim
> Abspielen eingebunden werden und welche Risiken/Abhilfemaßnahmen sich daraus ergeben. Die
> rechtliche Bewertung obliegt dem Betreiber.

Siehe auch das Gegenstück
[`railvieweradmin/docs/DATENSCHUTZ.md`](../../railvieweradmin/docs/DATENSCHUTZ.md) für die
Verwaltungsseite (API-Zugangsdaten, Upload, Rollenmodell). `mod_railviewer` besitzt **keine**
eigenen Datenbanktabellen für Zuweisungen/Fortschritt – diese liegen physisch im Plugin
`local_railvieweradmin` und werden von `mod_railviewer` beim Abspielen gelesen/geschrieben.

---

## 1. Zweck des Plugins

`mod_railviewer` ist die Kursaktivität, über die Auszubildende/Triebfahrzeugführer:innen
zugewiesene Streckenvideos abspielen. Jede Aktivitätsinstanz (`railviewer`-Tabelle) kann
optional auf eine feste Strecke beschränkt sein (`routeid`) oder als generischer Player dienen,
der die eigene Zuweisungsliste des Nutzers zeigt (`index.php`/`view.php`).

## 2. Verarbeitete personenbezogene Daten

| Tabelle / Feld | Eigentümer-Plugin | Datenart | Zweck |
|---|---|---|---|
| `railviewer.course`, `.routeid` | mod_railviewer | Kurs-/Strecken-Zuordnung der Aktivität | keine direkten Personendaten |
| `railviewer_assignments.userid`, `.completed_views`, `.lastviewed` | local_railvieweradmin (von mod_railviewer gelesen/aktualisiert) | Wer hat welche Strecke wie oft angesehen | Fortschrittsanzeige, Freischaltung/Nachweis |
| `railviewer_views.userid`, `.sectionid`, `.position`, `.timemodified` | local_railvieweradmin (von `play.php`/`complete_assignment.php` geschrieben) | genaue Wiedergabeposition je Abschnitt und Zeitpunkt | Fortsetzen an letzter Position, Abschlusserkennung |
| Session (`$USER->id`) | Moodle-Kern | technisch zur Autorisierung, welche Zuweisungen ein Nutzer sehen/abspielen darf (`railviewer_get_authorized_assignment()`) | Zugriffskontrolle |
| `localStorage` im Browser (`map.php`) | rein clientseitig, **kein** Server-Roundtrip | Sichtbarkeits-Zustand einer Kartenebene (An/Aus) | reine UI-Bequemlichkeit, keine Übertragung an den Server oder Dritte |

Es werden **keine** Namen, E-Mail-Adressen oder sonstigen Klardaten des Nutzers an Vimeo,
ignite.video, SharePoint/Microsoft oder den Kartendienst übermittelt – `$USER->id` wird
ausschließlich intern für Datenbank-Abfragen verwendet (Autorisierungsprüfung,
Fortschritts-Update), nie als Parameter in externen API-Aufrufen oder URLs.

## 3. Zugriffsberechtigungen

Definiert in `db/access.php`:

| Capability | Kontext | Bedeutung |
|---|---|---|
| `mod/railviewer:view` | `CONTEXT_MODULE`, Standard für `student`/`teacher`/`editingteacher`/`manager` | Zugriff auf die Aktivität. Bewusst **kein** `guest`-Zugriff, da Zuweisungen an konkrete Nutzer-IDs gebunden sind. |
| `mod/railviewer:addinstance` | `CONTEXT_COURSE` | Aktivität in einem Kurs anlegen (Standard-Moodle-Muster, `RISK_XSS`-Flag da Intro-Text als HTML gerendert wird). |

Ein Nutzer kann über `play.php` **ausschließlich eigene** Zuweisungen abspielen –
`railviewer_get_authorized_assignment()` prüft `userid = $USER->id` serverseitig, nicht nur
clientseitig. Für eine auf `routeid` beschränkte Aktivität ist zusätzlich sichergestellt, dass
nur Zuweisungen zu genau dieser Strecke abgespielt werden können.

## 4. Beim Abspielen kontaktierte externe Dienste

Diese Kontakte finden **im Browser der schulungspflichtigen Person** statt, sobald sie ein Video
öffnet (`play.php`) bzw. die Streckenkarte aufruft (`map.php`). Vollständiges Risikoregister mit
Abhilfemaßnahmen siehe [`railvieweradmin/docs/DATENSCHUTZ.md`, Abschnitt 5](../../railvieweradmin/docs/DATENSCHUTZ.md#5-fremdbibliotheken--externe-dienste--risikoregister)
– hier die Kurzfassung mit dem konkreten Auslöser in diesem Plugin:

| Dienst | Ausgelöst durch | Übertragene Daten | Risiko | Wichtigste Abhilfe |
|---|---|---|---|---|
| **Vimeo** (`player.vimeo.com/api/player.js` + iframe) | `play.php`, wenn **mindestens ein** Abschnitt der aktuellen Route `video_source=vimeo` verwendet (`$routeusesvimeo`, route-weit statt pro Abschnitt geprüft, da über die Vorherige/Nächste-Buttons ohne Seiten-Reload zwischen Abschnitten mit unterschiedlichem Anbieter gewechselt werden kann) | IP-Adresse, Geräte-/Browserdaten des Lernenden allein durchs Laden des Skripts, ggf. Vimeo-Cookies bei aktivem Vimeo-Abschnitt | Mittel–hoch (US-Anbieter) | AVV mit Vimeo; Videos auf „unlisted“ setzen (siehe Admin-Guide) |
| **ignite.video** (`play.ignite.video`, CDN `railsky.euvideocdn.com`) | `play.php`, wenn mindestens ein Abschnitt der Route `video_source=ignite` verwendet | IP-Adresse, Geräte-/Browserdaten des Lernenden | Mittel (Anbieter/Standort zu klären) | AVV prüfen, eigene Player-Domain via `ignite_embed_url_template` erwägen |
| **SharePoint/Microsoft Graph** (signierte SharePoint-/OneDrive-CDN-Adresse, aufgelöst über `local_railvieweradmin/sharepoint_stream.php`) | `play.php`, wenn mindestens ein Abschnitt der Route `video_source=sharepoint` verwendet - kein iframe/Skript, sondern ein normaler `<video>`-Tag (siehe Abschnitt 6 in `railvieweradmin/docs/DATENSCHUTZ.md`) | IP-Adresse, Geräte-/Browserdaten des Lernenden beim Laden der Videodatei - **kein** `$USER->id`/Moodle-Personenbezug, da die Zugriffsprüfung serverseitig vor der Umleitung erfolgt | Niedrig–mittel (i.d.R. dieselbe Organisation wie der Moodle-Betreiber) | Berechtigungsumfang der App-Registrierung möglichst eng fassen (`Sites.Selected` statt `Sites.Read.All`, siehe `docs/sharepoint-einrichtung.md`) |
| **player.js (Embedly-Protokoll)** (`thirdpartylibs/playerjs/player.js`, aus `local_railvieweradmin`) | `play.php` bindet die dort self-gehostete Datei ein, wenn mindestens ein Abschnitt der Route `video_source=ignite` verwendet (`$routeusesignite`) | – (lokal gehostet, kein externer Kontakt mehr) | Niedrig | Versionspflege wie bei Leaflet |
| **BKG/GeodatenZentrum WMTS** (`sgx.geodatenzentrum.de`) | `map.php` (Streckenkarte) | IP-Adresse pro geladener Kartenkachel | Niedrig–mittel (deutsche Bundesbehörde) | Datenschutzhinweis der BKG verlinken |
| **Leaflet.js** (`thirdpartylibs/leaflet/`, aus `local_railvieweradmin`) | `map.php` bindet die dort gebündelte Datei ein | – (lokal gehostet, kein externer Kontakt durch die Bibliothek selbst) | Niedrig | Versionspflege |

> **Behoben (2026-09-15):** Zwei Punkte in `play.php` wurden korrigiert: (1) Vimeo- und
> player.js-Skript wurden zuvor unconditional bei jedem Aufruf geladen, unabhängig vom
> tatsächlich verwendeten Anbieter der Route – jetzt nur noch, wenn die Route tatsächlich einen
> Abschnitt mit dem jeweiligen Anbieter enthält (live getestet: gemischte Upload+Vimeo-Route lädt
> nur `player.vimeo.com`, reine ignite-Route nur das eigene `player.js`). (2) `player.js` wurde
> zuvor live von `cdn.embed.ly` geladen, ist jetzt wie Leaflet unter `thirdpartylibs/playerjs/`
> self-gehostet – kein Drittanbieter-Kontakt mehr allein durchs Laden des Skripts.

## 5. Besonderheit: Abschluss-Hinweis mit Gegenrichtung

Beim Abschluss aller geforderten Durchläufe einer Strecke zeigt `play.php` optional einen Hinweis
auf die verknüpfte Gegenrichtung (Hin-/Rückfahrt-Paarung, `pairedrouteid`) inklusive Fortschritt
des **eigenen** Zuweisungsdatensatzes für diese Route. Es werden dabei keine Fortschrittsdaten
**anderer** Nutzer angezeigt oder abgefragt – die Abfrage ist über `complete_assignment.php`
ebenfalls hart auf `userid = $USER->id` beschränkt.

## 6. Bekannte strukturelle Lücken

Siehe [`railvieweradmin/docs/DATENSCHUTZ.md`, Abschnitt 6](../../railvieweradmin/docs/DATENSCHUTZ.md#6-bekannte-strukturelle-lücken-moodletotara-privacy-api) –
gilt unverändert auch für `mod_railviewer`, da beide Plugins denselben Datenbestand teilen:
kein `core_privacy`-Provider, kein Backup/Restore-Support. Betroffenenanfragen (Auskunft/Löschung)
müssen aktuell manuell durch DB-administrierende Personen bearbeitet werden.

## 7. Handlungsempfehlungen für den Betreiber

1. Siehe Empfehlungen im Admin-Guide (Abschnitt 7) – gelten vollumfänglich auch hier, da die
   externen Kontakte technisch aus `mod_railviewer`-Dateien (`play.php`, `map.php`) ausgelöst
   werden.
2. ~~`embed.ly player.js` self-hosten~~ – **erledigt** (Abschnitt 4): läuft jetzt über
   `thirdpartylibs/playerjs/player.js` in `local_railvieweradmin`, kein Laufzeit-Fremdkontakt
   mehr.
3. Lernende in einer Datenschutzerklärung/Belehrung darauf hinweisen, dass beim Abspielen
   Verbindungsdaten an Vimeo/ignite.video bzw. (bei `video_source=sharepoint`) an die
   SharePoint-Umgebung der eigenen Organisation übertragen werden.
