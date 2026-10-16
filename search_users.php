<?php
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
