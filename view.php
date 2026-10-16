<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Part of the mod_railviewer plugin.
 *
 * @package    mod_railviewer
 * @copyright  dpr-rail
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/railvieweradmin/lib.php');
require_once($CFG->dirroot . '/mod/railviewer/lib.php');
require_login();
$id = required_param('id', PARAM_INT); // Course module ID
$cm = get_coursemodule_from_id('railviewer', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('mod/railviewer:view', $context);
$PAGE->set_url('/mod/railviewer/view.php', ['id' => $id]);
$PAGE->set_title(format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));
$railviewer = $DB->get_record('railviewer', ['id' => $cm->instance], '*', MUST_EXIST);

echo $OUTPUT->header();
// Eigenes, kleines Stylesheet nur für diese Karten-/Grid-Ansicht - als <link> im Seitenkörper
// eingebunden wie schon bei play.php, da $PAGE->requires->css() nach header() nicht mehr
// erlaubt ist (siehe streckenmap.php/map.php-Fix).
echo html_writer::tag('link', '', ['rel' => 'stylesheet', 'href' => new moodle_url('/mod/railviewer/styles_view.css')]);

echo html_writer::start_div('railviewer-view-header');
echo $OUTPUT->heading(local_railvieweradmin_get_address_string('yourroutes', 'mod_railviewer'));

echo html_writer::start_div('railviewer-view-header-actions');
if (!empty(get_config('local_railvieweradmin', 'map_enabled')) && !empty(get_config('local_railvieweradmin', 'map_tile_url'))) {
    echo html_writer::link(new moodle_url('/mod/railviewer/map.php', ['id' => $id]),
        '🗺️ ' . get_string('streckenmap', 'mod_railviewer'), ['class' => 'btn btn-secondary railviewer-view-mapbtn']);
}
echo html_writer::link(new moodle_url('/mod/railviewer/index.php'),
    '📋 ' . get_string('overview_and_history', 'mod_railviewer'), ['class' => 'btn btn-secondary railviewer-view-mapbtn']);
// Lernmethoden-Feature: Fahrten erfassen und Fortschritt je Abschnitt/Methode ansehen.
if (\local_railvieweradmin\learning::enabled()) {
    echo html_writer::link(new moodle_url('/mod/railviewer/rides.php'),
        '🚆 ' . get_string('my_rides', 'local_railvieweradmin'), ['class' => 'btn btn-secondary railviewer-view-mapbtn']);
}
// Dauerhafter Bearbeiten-Link für Kursleitung/Manager, unabhängig davon, ob eine Route
// gebunden ist oder nicht - z.B. um die Strecken-Bindung nachträglich zu ändern.
if (has_capability('moodle/course:manageactivities', $context)) {
    echo html_writer::link(new moodle_url('/course/modedit.php', ['update' => $cm->id, 'return' => 1]),
        '⚙️ ' . get_string('editactivitysettings', 'mod_railviewer'), ['class' => 'btn btn-secondary railviewer-view-mapbtn']);
}
echo html_writer::end_div();
echo html_writer::end_div();

// Ist die Aktivität an genau eine Route gebunden, nur deren Zuweisung(en) zeigen. Ohne
// gebundene Route (Normalfall) ist diese Aktivität ein genereller Player: ALLE dem Nutzer
// zugewiesenen Routen werden gezeigt, unabhängig davon, wo/wie sie zugewiesen wurden (siehe
// railviewer_get_authorized_assignment() in lib.php für die dazu passende Absicherung beim
// eigentlichen Abspielen).
if (!empty($railviewer->routeid)) {
    $assignments = $DB->get_records('railviewer_assignments', ['userid' => $USER->id, 'routeid' => $railviewer->routeid]);
} else {
    $assignments = $DB->get_records('railviewer_assignments', ['userid' => $USER->id]);
}

if (\local_railvieweradmin\learning::enabled() && $assignments) {
    // Gesamtdauer des Auftrags und Hinweis auf noch offene Abschlusserklärung.
    $overview = \local_railvieweradmin\learning::user_overview((int) $USER->id);
    echo \local_railvieweradmin\ui::duration_line(['soll' => $overview['soll'], 'ist' => $overview['ist'], 'incomplete' => $overview['incomplete']]);
    $declstatus = \local_railvieweradmin\learning::declaration_status((int) $USER->id, $overview['allcomplete']);
    if ($declstatus === 'pending' || $declstatus === 'declined') {
        echo $OUTPUT->notification(html_writer::link(new moodle_url('/mod/railviewer/rides.php'),
            get_string('declaration_title', 'local_railvieweradmin')) . ': ' . get_string('declaration_open_hint', 'local_railvieweradmin'), 'warning');
    }
}

// Als Ausbilder zu bestätigende Lernfahrten.
echo \local_railvieweradmin\ui::confirmation_callout((int) $USER->id);

if (!$assignments) {
    echo $OUTPUT->notification(get_string('noroutesassigned', 'mod_railviewer'));
    echo $OUTPUT->footer();
    exit;
}

$cards = [];

foreach ($assignments as $a) {
    // Hole Route
    $route = $DB->get_record('railviewer_routes', ['id' => $a->routeid]);
    if (!$route) {
        continue; // Sicherheitscheck
    }
    
    // Hole alle Abschnitt-IDs der Route
    $sectionids = $DB->get_fieldset_select('railviewer_route_sections', 'sectionid', 'routeid = ?', [$route->id]);
    if (empty($sectionids)) {
        continue; // Keine Abschnitte, Route ignorieren
    }
    
    // Ermittle abgeschlossene Durchläufe durch Zählen der Marker mit sectionid = -1
    $completed_playcounts = $DB->count_records('railviewer_views', [
        'userid' => $USER->id, 
        'assignmentid' => $a->id,
        'sectionid' => -1  // Marker für abgeschlossene Durchläufe
    ]);
    
    // Benötigte Durchläufe aus Assignment
    $required_playcounts = $a->required_views;
    
    // Noch notwendige Durchläufe
    $remaining_playcounts = max(0, $required_playcounts - $completed_playcounts);
    
    // Ermittle nächsten Abschnitt für den aktuellen Durchlauf
    // Hole die höchste playcountid für dieses Assignment
    $last_play = $DB->get_record_sql("
        SELECT MAX(playcountid) AS maxid FROM {railviewer_views}
        WHERE assignmentid = ? AND userid = ?
    ", [$a->id, $USER->id]);
    
    $current_playcountid = ($last_play && $last_play->maxid) ? $last_play->maxid : 1;
    
    // Prüfe, ob für diese playcountid bereits ein Marker mit sectionid = -1 vorhanden ist
    $marker_exists = $DB->record_exists('railviewer_views', [
        'assignmentid' => $a->id,
        'userid' => $USER->id,
        'playcountid' => $current_playcountid,
        'sectionid' => -1,
    ]);
    
    if ($marker_exists) {
        $current_playcountid += 1;
    }
    
    // Hole bereits gesehene Abschnitt-IDs für den aktuellen Durchlauf
    list($in_sql, $params) = $DB->get_in_or_equal($sectionids, SQL_PARAMS_NAMED);
    $params['userid'] = $USER->id;
    $params['assignmentid'] = $a->id;
    $params['playcountid'] = $current_playcountid;
    
    $sql = "SELECT DISTINCT sectionid FROM {railviewer_views} 
            WHERE userid = :userid AND assignmentid = :assignmentid 
            AND playcountid = :playcountid AND sectionid $in_sql";
    $viewed_sectionids = $DB->get_fieldset_sql($sql, $params);
    
    // Ermittle noch nicht gesehene Abschnitte im aktuellen Durchlauf
    $not_completed_sections = array_diff($sectionids, $viewed_sectionids);
    
    // Nächsten Abschnitt auswählen
    if (!empty($not_completed_sections)) {
        $nextsectionid = min($not_completed_sections);
    } else {
        // Alle Abschnitte im aktuellen Durchlauf fertig, Start wieder mit erstem Abschnitt
        $nextsectionid = min($sectionids);
    }
    
    $url = new moodle_url('/mod/railviewer/play.php', [
        'assignmentid' => $a->id,
        'cmid' => $cm->id,
    ]);
    
    // Link-Text/Status-Badge je nach Fortschritt. Bei bereits erledigten Strecken zeigt schon
    // das grüne Badge den Status - der Button muss das nicht nochmal groß wiederholen, sondern
    // bietet dezent die Möglichkeit, sich die Strecke erneut anzusehen.
    if ($remaining_playcounts == 0) {
        $link_text = '🔁 ' . get_string('rewatch', 'mod_railviewer');
        $btnclass = 'btn btn-outline-secondary';
        $badgeclass = 'badge-success';
        $badgetext = get_string('status_erledigt', 'mod_railviewer');
        $barclass = 'railviewer-route-progress-complete';
    } elseif ($completed_playcounts > 0) {
        $link_text = '▶️ ' . get_string('continue', 'mod_railviewer');
        $btnclass = 'btn btn-primary';
        $badgeclass = 'badge-warning';
        $badgetext = get_string('status_in_bearbeitung', 'mod_railviewer');
        $barclass = 'railviewer-route-progress-partial';
    } else {
        $link_text = '▶️ ' . get_string('start', 'mod_railviewer');
        $btnclass = 'btn btn-primary';
        $badgeclass = 'badge-secondary';
        $badgetext = get_string('status_offen', 'mod_railviewer');
        $barclass = '';
    }

    $percent = $required_playcounts > 0 ? min(100, round(100 * $completed_playcounts / $required_playcounts)) : 0;

    $card = [
        'name' => format_string($route->name),
        'required' => $required_playcounts,
        'completed' => $completed_playcounts,
        'remaining' => $remaining_playcounts,
        'percent' => $percent,
        'barclass' => $barclass,
        'badgeclass' => $badgeclass,
        'badgetext' => $badgetext,
        'actionhtml' => html_writer::link($url, $link_text, ['class' => $btnclass]),
        'routeid' => $route->id,
        'pairedrouteid' => $route->pairedrouteid,
        'direction' => $route->direction,
    ];
    $cards[] = railviewer_apply_learning_to_card($card, $a);
}

// Offene/in Bearbeitung befindliche Strecken(-Paare) direkt sichtbar, erledigte eingeklappt
// darunter (die Historie weiter unten dokumentiert Abschlüsse ohnehin im Detail).
//
// Hin-/Rückfahrt-Gruppierung nur im generischen Player (keine an genau eine Route gebundene
// Aktivität): eine bewusst auf eine Route beschränkte Aktivität (restricttoroute) soll exakt
// bei dieser einen Route bleiben - die Gegenrichtung automatisch mit reinzuziehen würde diese
// Admin-Einschränkung stillschweigend aufweichen.
if (empty($railviewer->routeid)) {
    $groups = local_railvieweradmin_group_by_route_pairing($cards);
} else {
    $groups = array_map(function($card) { return ['type' => 'single', 'item' => $card]; }, $cards);
}
echo railviewer_render_route_grid($groups);

echo $OUTPUT->footer();
?>
