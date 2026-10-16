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

// Tippsuche für die Ausbilder-Auswahl (rides.php): liefert aktive Nutzer als JSON.
require_once('../../config.php');
require_once($CFG->dirroot . '/local/railvieweradmin/lib.php');
require_login();
require_sesskey();

header('Content-Type: application/json');
if (!\local_railvieweradmin\learning::enabled()) {
    echo json_encode([]);
    exit;
}
$q = optional_param('q', '', PARAM_TEXT);
echo json_encode(\local_railvieweradmin\rides::search_users($q, (int) $USER->id));
