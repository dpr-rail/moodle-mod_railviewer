<?php
$string['pluginname'] = 'Railviewer';
$string['railviewername'] = 'Bezeichnung';
$string['modulename'] = 'Railviewer';
$string['modulenameplural'] = 'Railviewers';
$string['modulename_help'] = 'Dieses Modul ermöglicht das dem Nutzer, Strecken mit Videoplayback und Fortschritts-Tracking anzusehen.';
$string['railviewer:addinstance'] = 'Neues Railviewer-Activity-Plugin hinzufügen';
$string['railviewer:view'] = 'Railviewer Inhalte ansehen';

$string['route'] = 'Strecke';
$string['requiredplaycounts'] = 'Benötigte Durchläufe';
$string['completedplaycounts'] = 'Abgeschlossene Durchläufe';
$string['remainingplaycounts'] = 'Noch notwendig';
$string['action'] = 'Ansehen';
$string['start'] = 'Starten';
$string['continue'] = 'Fortsetzen';
$string['completed'] = 'Abgeschlossen';
$string['viewlist'] = 'Abzuschließende Strecken';
$string['startview'] = 'Strecke starten';
$string['progress'] = 'Fortschritt';

$string['pluginadministration'] = 'Railviewer Administration';
$string['yourroutes'] = 'Zugewiesene Strecken';
$string['yourroutes_du'] = 'Dir zugewiesene Strecken';
$string['yourroutes_sie'] = 'Ihnen zugewiesene Strecken';
$string['noroutesassigned'] = 'Keine Strecken bisher zugewiesen';
$string['section'] = 'Abschnitt';
$string['nosectionsdefined'] = 'Keine Abschnitte definiert';
$string['notlinkedtoactivity'] = 'Aktuell keiner Aktivität zugeordnet oder nicht sichtbar';

$string['invalidassignmentid'] = 'Ungültige Zuweisungs-ID.';
$string['invalidcmid'] = 'Ungültige Kursmodul-ID.';

$string['videotitle'] = 'Video ansehen';
$string['nosectionsfound'] = 'Keine Abschnitte gefunden.';
$string['allplaythroughscompleted'] = 'Alle Durchläufe für diese Route wurden abgeschlossen.';
$string['allplaythroughscompleted_du'] = 'Glückwunsch! Du hast alle Durchläufe für diese Route abgeschlossen.';
$string['allplaythroughscompleted_sie'] = 'Glückwunsch! Sie haben alle Durchläufe für diese Route abgeschlossen.';
$string['returntripready'] = 'Alle Durchläufe abgeschlossen. Die Rückfahrt „__ROUTE__" ist bereit.';
$string['returntripready_du'] = 'Glückwunsch! Du hast alle Durchläufe abgeschlossen. Die Rückfahrt „__ROUTE__" ist bereit.';
$string['returntripready_sie'] = 'Glückwunsch! Sie haben alle Durchläufe abgeschlossen. Die Rückfahrt „__ROUTE__" ist bereit.';
$string['watch_again'] = '🔁 Diese Richtung nochmal ansehen';
$string['watch_return_trip'] = '🔀 Rückfahrt ansehen';
$string['back_to_overview'] = '📋 Zur Übersicht';
$string['playcountcompleted'] = 'Durchlauf abgeschlossen! Starte Durchlauf #{$a}...';
$string['confirm_leave_section'] = 'Der Fortschritt in diesem Abschnitt wurde eventuell noch nicht vollständig gespeichert. Trotzdem wechseln?';
$string['settingsinfo'] = 'Dieses Plugin hat keine eigenen Einstellungen. Zugangsdaten, Anrede und Overlay-Standardwerte werden über die <a href="{$a}">Railviewer Streckenverwaltung</a> konfiguriert.';

// Streckenkarten-Widget.
$string['streckenmap'] = 'Streckenkarte';
$string['back_to_listview'] = 'Zurück zur Listenansicht';
$string['back_to_assignments'] = 'Zurück zur Zuweisungsübersicht';
$string['adminview_notice'] = 'Admin-Ansicht: Du siehst hier die Streckenkarte von {$a} (rein lesend, nicht abspielbar).';
$string['streckenmap_legend_heading'] = 'Legende';
$string['status_none'] = 'Kein Video hinterlegt';
$string['status_offen'] = 'Offen';
$string['status_in_bearbeitung'] = 'In Bearbeitung';
$string['status_erledigt'] = 'Erledigt';
$string['show_tasklist'] = 'Aufgabenliste anzeigen';
$string['hide_tasklist'] = 'Aufgabenliste ausblenden';
$string['progress_tooltip_template'] = '{views} von {required} Durchläufen – noch {remaining} offen';
$string['progress_tooltip_done_template'] = '✓ abgeschlossen ({views}/{required})';
$string['history_heading'] = 'Historie';
$string['history_empty'] = 'Noch keine abgeschlossenen Durchläufe vorhanden.';
$string['history_completed_on'] = 'Abgeschlossen am';
$string['show_history'] = 'Historie anzeigen';
$string['hide_history'] = 'Historie ausblenden';
$string['completed_routes_heading'] = 'Erledigte Strecken ({$a})';
$string['rewatch'] = 'Erneut ansehen';
$string['roundtrip_label'] = 'Hin- & Rückfahrt';
$string['roundtrip_combined_progress'] = '{$a->completed} / {$a->required} insgesamt';
$string['diag_no_activity_exists'] = '(Admin-Hinweis: In keinem Kurs existiert eine Railviewer-Aktivität, die diese Strecke zeigen könnte - auch keine generische. Bitte eine Aktivität anlegen.)';
$string['diag_no_course_access'] = '(Admin-Hinweis: Kein Kurszugriff auf "{$a}" - weder eingeschrieben noch anderweitig berechtigt.)';
$string['diag_not_visible'] = '(Admin-Hinweis: Die Aktivität in "{$a}" ist für Sie nicht sichtbar - versteckt oder durch Verfügbarkeitsbedingungen eingeschränkt.)';
$string['diag_no_capability'] = '(Admin-Hinweis: Fehlende Capability mod/railviewer:view in "{$a}".)';
$string['reviewmode_notice'] = 'Diese Strecke wurde bereits vollständig abgeschlossen - Sie sehen sie sich hier erneut zur Wiederholung an, ohne dass dadurch weitere Durchläufe erforderlich werden.';
$string['playpause'] = 'Abspielen/Pause';
$string['mutetoggle'] = 'Stumm/Laut';
$string['seekahead_blocked'] = 'Vorspulen ist erst möglich, sobald Sie diese Stelle im Video regulär erreicht haben. Zurückspulen zum Wiederholen ist jederzeit möglich.';
$string['playbackspeed'] = 'Wiedergabegeschwindigkeit';
$string['speed_unsupported_ignite'] = 'Wiedergabegeschwindigkeit wird für ignite.video-Videos nicht unterstützt.';
$string['overview_and_history'] = 'Übersicht & Historie';
$string['restricttoroute'] = 'Auf eine Strecke beschränken';
$string['restricttoroute_label'] = 'Diese Aktivität nur für eine einzelne, unten ausgewählte Strecke verwenden';
$string['restricttoroute_help'] = 'Standardmäßig (Haken aus) zeigt diese Aktivität jedem Nutzer ALLE ihm zugewiesenen Strecken - unabhängig davon, wo/wie sie zugewiesen wurden. Das ist der Normalfall: es muss nicht für jede zugewiesene Strecke eine eigene Aktivität angelegt werden.

Setzen Sie den Haken nur, wenn diese Aktivität gezielt genau eine bestimmte Strecke zeigen soll, z.B. um sie in ein bestimmtes Kursthema einzubetten.';
$string['invalidroute'] = 'Ungültige Strecke.';
$string['editactivitysettings'] = 'Aktivität bearbeiten';

