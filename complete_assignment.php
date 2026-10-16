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

$data = json_decode(file_get_contents('php://input'));

if (!is_object($data) || !isset($data->sesskey) || !confirm_sesskey($data->sesskey)) {
    http_response_code(403);
    echo json_encode(['error' => get_string('invalidsesskey', 'error')]);
    exit;
}

$assignmentid = isset($data->assignmentid) ? clean_param($data->assignmentid, PARAM_INT) : 0;
$cmid = isset($data->cmid) ? clean_param($data->cmid, PARAM_INT) : 0;
$userid = $USER->id;

if (!$assignmentid || !$cmid) {
    http_response_code(400);
    echo json_encode(['error' => get_string('invalidassignmentid', 'railviewer')]);
    exit;
}

global $DB;

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
$assignment = railviewer_get_authorized_assignment($assignmentid, $userid, $railviewer);

// Marker zählen, completed_views abgleichen und ggf. Abschluss-Events auslösen
$assignment = local_railvieweradmin_sync_assignment_progress($assignmentid);
$actualCompletedViews = (int) $assignment->completed_views;

// Prüfen ob alle Durchläufe abgeschlossen sind
$is_complete = ($actualCompletedViews >= $assignment->required_views);

// Falls diese Route eine verknüpfte Gegenrichtung hat und der Nutzer dafür ebenfalls eine
// Zuweisung besitzt, dem Client mitgeben - play.php bietet dem Nutzer damit direkt an, die
// Gegenrichtung anzusehen statt nur generisch "alles fertig" zu melden.
$partner = null;
if ($is_complete) {
    $route = $DB->get_record('railviewer_routes', ['id' => $assignment->routeid]);
    if ($route && !empty($route->pairedrouteid)) {
        $partnerassignment = $DB->get_record('railviewer_assignments', [
            'userid' => $userid,
            'routeid' => $route->pairedrouteid,
        ]);
        if ($partnerassignment) {
            $partner = [
                'assignmentid' => (int) $partnerassignment->id,
                'routename' => $DB->get_field('railviewer_routes', 'name', ['id' => $route->pairedrouteid]),
                'iscomplete' => $partnerassignment->completed_views >= $partnerassignment->required_views,
            ];
        }
    }
}

// Antwort zurückgeben
http_response_code(200);
echo json_encode([
    'success' => true,
    'completed_views' => $actualCompletedViews,
    'required_views' => (int)$assignment->required_views,
    'is_complete' => $is_complete,
    'partner' => $partner,
]);
