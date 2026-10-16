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

// Ausbilder bestätigt oder lehnt eine Lernfahrt ab.
require_once('../../config.php');
require_once($CFG->dirroot . '/local/railvieweradmin/lib.php');
require_login();

$rideid = required_param('id', PARAM_INT);
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/mod/railviewer/ride_confirm.php', ['id' => $rideid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('ride_confirm_title', 'local_railvieweradmin'));
$PAGE->set_heading(get_string('ride_confirm_title', 'local_railvieweradmin'));

global $DB, $USER;

if (!\local_railvieweradmin\learning::enabled()) {
    throw new moodle_exception('learning_feature_disabled', 'local_railvieweradmin');
}

$ride = $DB->get_record('railviewer_rides', ['id' => $rideid], '*', MUST_EXIST);
if ((int) $ride->trainerid !== (int) $USER->id) {
    throw new moodle_exception('ride_cannot_respond', 'local_railvieweradmin');
}

$back = new moodle_url('/mod/railviewer/rides.php');
if (optional_param('decision', '', PARAM_ALPHA) !== '' && $ride->status === 'pending') {
    require_sesskey();
    $accept = optional_param('decision', '', PARAM_ALPHA) === 'confirm';
    $reason = optional_param('reason', '', PARAM_TEXT);
    \local_railvieweradmin\rides::respond($rideid, (int) $USER->id, $accept, $reason);
    redirect($back, get_string($accept ? 'ride_confirmed_msg' : 'ride_rejected_msg', 'local_railvieweradmin'),
        null, \core\output\notification::NOTIFY_SUCCESS);
}

$rider = $DB->get_record('user', ['id' => $ride->userid], '*', MUST_EXIST);
$method = $DB->get_record('railviewer_methods', ['id' => $ride->methodid]);
$route = $DB->get_record('railviewer_routes', ['id' => $ride->routeid]);
$sections = $DB->get_records_sql(
    "SELECT s.id, s.name FROM {railviewer_ride_sections} rs JOIN {railviewer_sections} s ON s.id = rs.sectionid
       JOIN {railviewer_route_sections} rrs ON rrs.sectionid = s.id AND rrs.routeid = :rid
      WHERE rs.rideid = :ride ORDER BY rrs.sortorder", ['rid' => $ride->routeid, 'ride' => $rideid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ride_confirm_title', 'local_railvieweradmin'));

$table = new html_table();
$table->data = [
    [get_string('ride_rider', 'local_railvieweradmin'), s(fullname($rider))],
    [get_string('route', 'local_railvieweradmin'), $route ? format_string($route->name) : '–'],
    [get_string('ride_sections', 'local_railvieweradmin'), s(implode(' → ', array_map(function($s) { return format_string($s->name); }, $sections)))],
    [get_string('ride_method', 'local_railvieweradmin'), $method ? format_string($method->name) : '–'],
    [get_string('ride_date', 'local_railvieweradmin'), userdate($ride->ridedate, get_string('strftimedatefullshort', 'langconfig'))],
    [get_string('ride_train', 'local_railvieweradmin'), s($ride->trainnumber)],
    [get_string('status', 'local_railvieweradmin'), \local_railvieweradmin\ui::ride_status($ride->status)],
];
echo html_writer::table($table);

if ($ride->status === 'pending') {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::div(html_writer::label(get_string('ride_reject_reason', 'local_railvieweradmin'), 'reason') .
        html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason', 'id' => 'reason', 'class' => 'form-control']), 'form-group');
    echo html_writer::tag('button', get_string('ride_confirm_btn', 'local_railvieweradmin'),
        ['type' => 'submit', 'name' => 'decision', 'value' => 'confirm', 'class' => 'btn btn-success']) . ' ';
    echo html_writer::tag('button', get_string('ride_reject_btn', 'local_railvieweradmin'),
        ['type' => 'submit', 'name' => 'decision', 'value' => 'reject', 'class' => 'btn btn-danger']);
    echo html_writer::end_tag('form');
} else {
    echo html_writer::tag('p', get_string('ride_already_answered', 'local_railvieweradmin'));
}
echo html_writer::link($back, get_string('my_rides', 'local_railvieweradmin'));
echo $OUTPUT->footer();
