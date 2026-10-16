<?php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/railvieweradmin/lib.php');
require_once($CFG->dirroot . '/mod/railviewer/lib.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/mod/railviewer/index.php'));
$PAGE->set_title(get_string('videotitle', 'mod_railviewer'));
$PAGE->set_heading(get_string('videotitle', 'mod_railviewer'));

global $USER, $DB;

$assignments = $DB->get_records('railviewer_assignments', ['userid' => $USER->id]);

echo $OUTPUT->header();
// Gleiches Karten-Stylesheet wie view.php - $PAGE->requires->css() geht nach header() nicht
// mehr (siehe dortiger Kommentar).
echo html_writer::tag('link', '', ['rel' => 'stylesheet', 'href' => new moodle_url('/mod/railviewer/styles_view.css')]);

echo html_writer::tag('h2', local_railvieweradmin_get_address_string('yourroutes', 'mod_railviewer'));

// Als Ausbilder zu bestätigende Lernfahrten – auch sichtbar, wenn man selbst keine Zuweisung hat.
echo \local_railvieweradmin\ui::confirmation_callout((int) $USER->id);

if (empty($assignments)) {
    echo html_writer::div(get_string('noroutesassigned', 'mod_railviewer'));
    echo $OUTPUT->footer();
    exit;
}

// Lernmethoden-Feature: Gesamtstand des Auftrags (Dauer, offene Abschlusserklärung) und Link
// zu den Lernfahrten oberhalb der Streckenkarten.
if (\local_railvieweradmin\learning::enabled()) {
    $overview = \local_railvieweradmin\learning::user_overview((int) $USER->id);
    echo html_writer::div(html_writer::link(new moodle_url('/mod/railviewer/rides.php'),
        '🚆 ' . get_string('my_rides', 'local_railvieweradmin'), ['class' => 'btn btn-secondary']), 'mb-2');
    echo \local_railvieweradmin\ui::duration_line(['soll' => $overview['soll'], 'ist' => $overview['ist'], 'incomplete' => $overview['incomplete']]);
    $declstatus = \local_railvieweradmin\learning::declaration_status((int) $USER->id, $overview['allcomplete']);
    if ($declstatus === 'pending' || $declstatus === 'declined') {
        echo $OUTPUT->notification(html_writer::link(new moodle_url('/mod/railviewer/rides.php'),
            get_string('declaration_title', 'local_railvieweradmin')) . ': ' . get_string('declaration_open_hint', 'local_railvieweradmin'), 'warning');
    } else if ($overview['items'] && \local_railvieweradmin\learning::order_complete((int) $USER->id, $overview)) {
        echo $OUTPUT->notification(get_string('order_complete', 'local_railvieweradmin'), 'success');
    }
}

/**
 * Findet ein für den Nutzer tatsächlich zugängliches mod_railviewer-Kursmodul, über das eine
 * bestimmte Route gespielt werden darf, oder null falls keins existiert/sichtbar ist.
 *
 * Zwei Arten von Aktivitäten kommen dafür infrage (siehe railviewer_get_authorized_assignment()
 * in lib.php für die dazu passende serverseitige Absicherung):
 *  - eine Aktivität, die fest an GENAU DIESE Route gebunden ist (r.routeid = die gesuchte
 *    Route) - wird bevorzugt, falls vorhanden.
 *  - eine "generelle" Aktivität ohne gebundene Route - darf als Tür für JEDE dem Nutzer
 *    zugewiesene Route dienen. Das ist der Normalfall: Kursleitungen legen typischerweise
 *    eine einzige generelle Railviewer-Aktivität pro Kurs an, nicht eine eigene Aktivität
 *    pro Route. "Keine Route gebunden" kann sowohl als NULL (nie gesetzt) als auch als 0
 *    (Formular: leere Select-Auswahl wird von PARAM_INT beim Speichern zu 0, nicht zu NULL)
 *    in der Datenbank stehen - beide Fälle müssen hier als generisch behandelt werden.
 *
 * Bewusst NICHT auf enrol_get_users_courses() vorgefiltert: das liefert nur Kurse, in denen
 * der Nutzer eine ECHTE Einschreibung hat, und übergeht damit z.B. Admins/Manager, die einen
 * Kurs nur über eine Rollenzuweisung sehen (kategorie-/systemweit) statt über eine Einschreibung
 * - für die wäre sonst NIE ein zugängliches Kursmodul auffindbar, egal wie die Aktivität
 * konfiguriert ist. can_access_course() ist Moodles eigene, vollständige Zugriffsprüfung
 * (berücksichtigt Einschreibung, Rollenzuweisungen, Admin-Bypass etc.) und daher robuster.
 */
$findaccessiblecm = function($routeid) use ($DB, $USER) {
    $sql = "SELECT cm.id AS cmid, cm.course AS courseid,
                   CASE WHEN r.routeid = :routeid2 THEN 0 ELSE 1 END AS specificity
              FROM {railviewer} r
              JOIN {course_modules} cm ON cm.instance = r.id
              JOIN {modules} m ON m.id = cm.module AND m.name = 'railviewer'
             WHERE (r.routeid = :routeid OR r.routeid IS NULL OR r.routeid = 0)
          ORDER BY specificity ASC";
    $candidates = $DB->get_records_sql($sql, ['routeid' => $routeid, 'routeid2' => $routeid]);

    foreach ($candidates as $candidate) {
        $course = get_course($candidate->courseid);
        if (!can_access_course($course, $USER)) {
            continue;
        }
        $modinfo = get_fast_modinfo($course, $USER->id);
        $cm = $modinfo->get_cm($candidate->cmid);
        if ($cm && $cm->uservisible && has_capability('mod/railviewer:view', $cm->context)) {
            return $cm;
        }
    }

    return null;
};

/**
 * Nur für Admins/Site-Config-Berechtigte: erklärt in Klartext, warum für eine Route kein
 * Kursmodul gefunden wurde, damit sich Fehlkonfigurationen (keine Aktivität, keine
 * Kurszugriffsberechtigung, Aktivität versteckt, fehlende Capability) ohne DB-Zugriff
 * diagnostizieren lassen.
 */
$diagnosenocm = function($routeid) use ($DB, $USER) {
    $sql = "SELECT cm.id AS cmid, cm.course AS courseid
              FROM {railviewer} r
              JOIN {course_modules} cm ON cm.instance = r.id
              JOIN {modules} m ON m.id = cm.module AND m.name = 'railviewer'
             WHERE (r.routeid = :routeid OR r.routeid IS NULL OR r.routeid = 0)";
    $candidates = $DB->get_records_sql($sql, ['routeid' => $routeid]);

    if (empty($candidates)) {
        return get_string('diag_no_activity_exists', 'mod_railviewer');
    }

    $reasons = [];
    foreach ($candidates as $candidate) {
        $course = get_course($candidate->courseid);
        if (!can_access_course($course, $USER)) {
            $reasons[] = get_string('diag_no_course_access', 'mod_railviewer', format_string($course->fullname));
            continue;
        }
        $modinfo = get_fast_modinfo($course, $USER->id);
        $cm = $modinfo->get_cm($candidate->cmid);
        if (!$cm || !$cm->uservisible) {
            $reasons[] = get_string('diag_not_visible', 'mod_railviewer', format_string($course->fullname));
            continue;
        }
        if (!has_capability('mod/railviewer:view', $cm->context)) {
            $reasons[] = get_string('diag_no_capability', 'mod_railviewer', format_string($course->fullname));
        }
    }

    return implode(' ', array_unique($reasons));
};

// Eine Karte je Zuweisung, im selben Stil wie view.php (dort auf eine einzelne
// Aktivitätsinstanz/Route beschränkt, hier plugin-weit über alle Kurse hinweg).
$cards = [];

foreach ($assignments as $assignment) {
    $route = $DB->get_record('railviewer_routes', ['id' => $assignment->routeid]);
    if (!$route) {
        continue;
    }

    $sectionids = $DB->get_fieldset_select('railviewer_route_sections', 'sectionid', 'routeid = ?', [$route->id]);
    $cm = $findaccessiblecm($route->id);

    if (empty($sectionids)) {
        $cards[] = [
            'name' => format_string($route->name),
            'nosections' => true,
            'nosectionstext' => get_string('nosectionsdefined', 'mod_railviewer'),
            'actionhtml' => '',
            'routeid' => $route->id,
            'pairedrouteid' => $route->pairedrouteid,
            'direction' => $route->direction,
        ];
        continue;
    }

    // Abgeschlossene Durchläufe = Marker mit sectionid = -1 (siehe view.php/play.php).
    $completed = $DB->count_records('railviewer_views', [
        'userid' => $USER->id,
        'assignmentid' => $assignment->id,
        'sectionid' => -1,
    ]);
    $required = $assignment->required_views;
    $remaining = max(0, $required - $completed);
    $percent = $required > 0 ? min(100, round(100 * $completed / $required)) : 0;

    // Bei bereits erledigten Strecken zeigt schon das grüne Badge den Status - der Button muss
    // das nicht nochmal groß wiederholen, sondern bietet dezent die Möglichkeit, sich die
    // Strecke erneut anzusehen.
    if ($remaining == 0) {
        $linktext = '🔁 ' . get_string('rewatch', 'mod_railviewer');
        $btnclass = 'btn btn-outline-secondary';
        $badgeclass = 'badge-success';
        $badgetext = get_string('status_erledigt', 'mod_railviewer');
        $barclass = 'railviewer-route-progress-complete';
    } else if ($completed > 0) {
        $linktext = '▶️ ' . get_string('continue', 'mod_railviewer');
        $btnclass = 'btn btn-primary';
        $badgeclass = 'badge-warning';
        $badgetext = get_string('status_in_bearbeitung', 'mod_railviewer');
        $barclass = 'railviewer-route-progress-partial';
    } else {
        $linktext = '▶️ ' . get_string('start', 'mod_railviewer');
        $btnclass = 'btn btn-primary';
        $badgeclass = 'badge-secondary';
        $badgetext = get_string('status_offen', 'mod_railviewer');
        $barclass = '';
    }

    if ($cm) {
        $starturl = new moodle_url('/mod/railviewer/play.php', ['assignmentid' => $assignment->id, 'cmid' => $cm->id]);
        $actionhtml = html_writer::link($starturl, $linktext, ['class' => $btnclass]);
    } else {
        $actionhtml = html_writer::div(get_string('notlinkedtoactivity', 'mod_railviewer'), 'text-muted');
        // Nur für Admins/Site-Config-Berechtigte: Klartext-Grund direkt unter dem Hinweis, ohne
        // dass jemand in die Datenbank schauen muss.
        if (has_capability('moodle/site:config', $context)) {
            $actionhtml .= html_writer::div($diagnosenocm($route->id), 'text-muted small');
        }
    }

    $card = [
        'name' => format_string($route->name),
        'completed' => $completed,
        'required' => $required,
        'remaining' => $remaining,
        'percent' => $percent,
        'barclass' => $barclass,
        'badgeclass' => $badgeclass,
        'badgetext' => $badgetext,
        'actionhtml' => $actionhtml,
        'routeid' => $route->id,
        'pairedrouteid' => $route->pairedrouteid,
        'direction' => $route->direction,
    ];
    $cards[] = railviewer_apply_learning_to_card($card, $assignment);
}

// Offene/in Bearbeitung befindliche Strecken(-Paare) direkt sichtbar, erledigte eingeklappt
// darunter. Gruppierung fasst Hin-/Rückfahrt-Paare zusammen, wenn der Nutzer tatsächlich beide
// Richtungen zugewiesen bekommen hat (siehe local_railvieweradmin_group_by_route_pairing()).
echo railviewer_render_route_grid(local_railvieweradmin_group_by_route_pairing($cards));

// -------------------------------------------------------------------------
// Historie: bisher abgeschlossene Durchläufe (railviewer_views mit sectionid = -1
// markieren jeweils den Abschluss eines vollständigen Durchlaufs, siehe log.php/play.php).
// Standardmäßig ausgeblendet, per Umschalter ein-/ausblendbar (wie die Aufgabenliste der
// Kartenansicht) - die meisten Nutzer wollen primär sehen, was noch zu tun ist.
// -------------------------------------------------------------------------
$history = $DB->get_records_sql("
    SELECT v.id, v.timemodified, r.name AS routename
      FROM {railviewer_views} v
      JOIN {railviewer_routes} r ON r.id = v.routeid
     WHERE v.userid = :userid AND v.sectionid = -1
  ORDER BY v.timemodified DESC
", ['userid' => $USER->id], 0, 50);

echo html_writer::tag('button', get_string('show_history', 'mod_railviewer') . ' ▾',
    ['type' => 'button', 'id' => 'railviewer-history-toggle', 'class' => 'railviewer-map-tasklist-toggle']);
echo html_writer::start_div('', ['id' => 'railviewer-history-body', 'hidden' => 'hidden']);
echo $OUTPUT->heading(get_string('history_heading', 'mod_railviewer'), 3);

if (empty($history)) {
    echo html_writer::div(get_string('history_empty', 'mod_railviewer'), 'text-muted');
} else {
    $historytable = new html_table();
    $historytable->head = [get_string('route', 'mod_railviewer'), get_string('history_completed_on', 'mod_railviewer')];
    $historytable->attributes['class'] = 'generaltable';
    foreach ($history as $entry) {
        $historytable->data[] = [format_string($entry->routename), userdate($entry->timemodified)];
    }
    echo html_writer::table($historytable);
}
echo html_writer::end_div();

$PAGE->requires->js_init_code("
    document.getElementById('railviewer-history-toggle').addEventListener('click', function() {
        var body = document.getElementById('railviewer-history-body');
        var wasvisible = !body.hidden;
        body.hidden = wasvisible;
        this.textContent = wasvisible
            ? " . json_encode(get_string('show_history', 'mod_railviewer')) . " + ' ▾'
            : " . json_encode(get_string('hide_history', 'mod_railviewer')) . " + ' ▴';
    });
");

echo $OUTPUT->footer();
