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
require_once($CFG->dirroot . '/mod/railviewer/lib.php');
require_once($CFG->dirroot . '/local/railvieweradmin/lib.php');
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['error' => get_string('invalidaccess', 'error')]);
    exit;
}

$data = json_decode(file_get_contents('php://input'));

if (!is_object($data) || !isset($data->sesskey) || !confirm_sesskey($data->sesskey)) {
    http_response_code(403);
    echo json_encode(['error' => get_string('invalidsesskey', 'error')]);
    exit;
}

// Parameter-Check
if (!isset($data->assignmentid, $data->cmid, $data->sectionid, $data->position, $data->playcountid)) {
    http_response_code(400);
    echo json_encode(['error' => get_string('missingparam', 'error', 'assignmentid, cmid, sectionid, position, playcountid')]);
    exit;
}

$assignmentid = clean_param($data->assignmentid, PARAM_INT);
$cmid = clean_param($data->cmid, PARAM_INT);
$sectionid = clean_param($data->sectionid, PARAM_INT);
$position = clean_param($data->position, PARAM_INT);
$playcountid = clean_param($data->playcountid, PARAM_INT);
$event = isset($data->event) ? clean_param($data->event, PARAM_ALPHAEXT) : 'progress';

global $DB, $USER;

$cm = get_coursemodule_from_id('railviewer', $cmid, 0, false, IGNORE_MISSING);
if (!$cm) {
    http_response_code(400);
    echo json_encode(['error' => get_string('invalidcmid', 'railviewer')]);
    exit;
}
$context = context_module::instance($cm->id);
require_capability('mod/railviewer:view', $context);
$railviewer = $DB->get_record('railviewer', ['id' => $cm->instance], '*', MUST_EXIST);

// Assignment laden - siehe railviewer_get_authorized_assignment() für die Zugriffsregeln.
$assignment = railviewer_get_authorized_assignment($assignmentid, $USER->id, $railviewer);

// Spezialbehandlung für sectionid = -1 (Durchlauf-Marker)
if ($sectionid == -1) {
    // Prüfen ob dieser Marker bereits existiert
    $existing_marker = $DB->get_record('railviewer_views', [
        'assignmentid' => $assignmentid,
        'userid' => $USER->id,
        'playcountid' => $playcountid,
        'sectionid' => -1
    ]);

    if (!$existing_marker) {
        // Marker einfügen
        $marker = (object)[
            'assignmentid' => $assignmentid,
            'userid' => $USER->id,
            'routeid' => $assignment->routeid,
            'sectionid' => -1,
            'playcountid' => $playcountid,
            'position' => 0,
            'timemodified' => time()
        ];
        $DB->insert_record('railviewer_views', $marker);

        // completed_views mit den Markern abgleichen und ggf. Abschluss-Events auslösen
        local_railvieweradmin_sync_assignment_progress($assignment->id);
    }

    http_response_code(200);
    echo json_encode(['status' => 'ok', 'marker_set' => !$existing_marker]);
    exit;
}

// Normale Section-Logs verarbeiten
$params = [
    'assignmentid' => $assignmentid,
    'userid' => $USER->id,
    'routeid' => $assignment->routeid,
    'sectionid' => $sectionid,
    'playcountid' => $playcountid,
];

// View aktualisieren oder einfügen
$existing = $DB->get_record('railviewer_views', $params);

if ($existing) {
    $existing->position = $position;
    $existing->timemodified = time();
    $DB->update_record('railviewer_views', $existing);
} else {
    $new = (object) $params;
    $new->position = $position;
    $new->timemodified = time();
    $DB->insert_record('railviewer_views', $new);
}

// Prüfen ob das ended Event kam und ob der Abschnitt der letzte der Route ist
if ($event === 'ended') {
    // Ermittle die höchste sortorder für diese Route
    $max_sortorder = $DB->get_field_sql("
        SELECT MAX(sortorder)
        FROM {railviewer_route_sections}
        WHERE routeid = ?
    ", [$assignment->routeid]);

    // Ermittle die sortorder der aktuellen Section
    $current_sortorder = $DB->get_field_sql("
        SELECT sortorder
        FROM {railviewer_route_sections}
        WHERE routeid = ? AND sectionid = ?
    ", [$assignment->routeid, $sectionid]);

    // Ist dies die letzte Section der Route?
    if ($current_sortorder && $current_sortorder == $max_sortorder) {
        // Prüfen ob alle Sections dieser Route im aktuellen Durchlauf gesehen wurden
        $total_sections = $DB->count_records('railviewer_route_sections', ['routeid' => $assignment->routeid]);
        $seen_sections = $DB->count_records_sql("
            SELECT COUNT(DISTINCT sectionid)
            FROM {railviewer_views}
            WHERE assignmentid = ? AND userid = ? AND playcountid = ? AND sectionid > 0
        ", [$assignmentid, $USER->id, $playcountid]);

        // Prüfen ob bereits ein Marker für diesen Durchlauf existiert
        $already_completed = $DB->record_exists('railviewer_views', [
            'assignmentid' => $assignmentid,
            'userid' => $USER->id,
            'playcountid' => $playcountid,
            'sectionid' => -1
        ]);

        // Einfache Plausibilisierung: Zwischen dem ersten Log-Eintrag dieses Durchlaufs und
        // jetzt muss mindestens ein Großteil der Gesamt-Videolänge der Route vergangen sein.
        // Verhindert triviales Vortäuschen eines abgeschlossenen Durchlaufs durch direkte
        // fetch()-Aufrufe ohne echtes Ansehen der Videos. Kein Anspruch auf DRM-Niveau; falls
        // für Abschnitte keine duration gepflegt ist, wird die Prüfung übersprungen.
        $firstview = $DB->get_field_sql(
            "SELECT MIN(timemodified) FROM {railviewer_views}
              WHERE assignmentid = ? AND userid = ? AND playcountid = ? AND sectionid > 0",
            [$assignmentid, $USER->id, $playcountid]
        );
        $totalduration = $DB->get_field_sql(
            "SELECT SUM(s.duration)
               FROM {railviewer_route_sections} rs
               JOIN {railviewer_sections} s ON s.id = rs.sectionid
              WHERE rs.routeid = ?",
            [$assignment->routeid]
        );
        $plausible = empty($totalduration) || empty($firstview) || (time() - $firstview) >= ($totalduration * 0.8);

        if ($seen_sections >= $total_sections && !$already_completed && $plausible) {
            // Marker für abgeschlossenen Durchlauf speichern
            $marker = (object)[
                'assignmentid' => $assignmentid,
                'userid' => $USER->id,
                'routeid' => $assignment->routeid,
                'sectionid' => -1,
                'playcountid' => $playcountid,
                'position' => 0,
                'timemodified' => time()
            ];
            $DB->insert_record('railviewer_views', $marker);
            local_railvieweradmin_sync_assignment_progress($assignment->id);
        }
    }
}

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'event' => $event,
    'sectionid' => $sectionid,
    'playcountid' => $playcountid
]);
