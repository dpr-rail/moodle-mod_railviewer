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
 * Unit-Tests für railviewer_get_authorized_assignment() (sicherheitsrelevant: verhindert, dass
 * eine assignmentid einer fremden Person oder - bei einer auf eine Route beschränkten
 * Aktivität - eine nicht dazugehörige Route abgespielt werden kann) sowie die reine
 * Gruppierungs-Statuslogik railviewer_route_group_is_done().
 *
 * @package    mod_railviewer
 * @copyright  2026 Railviewer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/railviewer/lib.php');
require_once($CFG->dirroot . '/local/railvieweradmin/lib.php');

class mod_railviewer_lib_testcase extends advanced_testcase {

    protected function create_route($name) {
        global $DB;
        return $DB->insert_record('railviewer_routes', (object) [
            'name' => $name,
            'description' => '',
            'pairedrouteid' => null,
            'direction' => null,
        ]);
    }

    protected function create_assignment($userid, $routeid, $requiredviews = 1) {
        global $DB;
        return $DB->insert_record('railviewer_assignments', (object) [
            'userid' => $userid,
            'routeid' => $routeid,
            'required_views' => $requiredviews,
            'completed_views' => 0,
            'lastviewed' => null,
        ]);
    }

    // ------------------------------------------------------------------
    // railviewer_get_authorized_assignment()
    // ------------------------------------------------------------------

    public function test_get_authorized_assignment_returns_own_assignment() {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $routeid = $this->create_route('Route A');
        $assignmentid = $this->create_assignment($user->id, $routeid);

        // Genereller Player (routeid leer) - darf jede eigene Zuweisung abspielen.
        $railviewer = (object) ['routeid' => null];

        $assignment = railviewer_get_authorized_assignment($assignmentid, $user->id, $railviewer);

        $this->assertEquals($assignmentid, $assignment->id);
        $this->assertEquals($routeid, $assignment->routeid);
    }

    public function test_get_authorized_assignment_rejects_foreign_users_assignment() {
        $this->resetAfterTest(true);

        $owner = $this->getDataGenerator()->create_user();
        $attacker = $this->getDataGenerator()->create_user();
        $routeid = $this->create_route('Route A');
        $assignmentid = $this->create_assignment($owner->id, $routeid);

        $railviewer = (object) ['routeid' => null];

        // Sicherheitskritisch: eine fremde assignmentid darf NIEMALS erreichbar sein, auch nicht
        // wenn sie erraten/durchprobiert wird (userid ist Teil der WHERE-Bedingung, nicht nur
        // eine nachträgliche Prüfung).
        $this->expectException(dml_missing_record_exception::class);
        railviewer_get_authorized_assignment($assignmentid, $attacker->id, $railviewer);
    }

    public function test_get_authorized_assignment_enforces_route_restriction() {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $allowedroute = $this->create_route('Erlaubte Route');
        $otherroute = $this->create_route('Andere Route');
        // Nutzer hat BEIDE Routen zugewiesen ...
        $this->create_assignment($user->id, $allowedroute);
        $otherassignmentid = $this->create_assignment($user->id, $otherroute);

        // ... aber diese Aktivität ist fest an $allowedroute gebunden - die Zuweisung zur
        // anderen (eigenen!) Route darf über DIESE cmid trotzdem nicht erreichbar sein.
        $railviewer = (object) ['routeid' => $allowedroute];

        $this->expectException(dml_missing_record_exception::class);
        railviewer_get_authorized_assignment($otherassignmentid, $user->id, $railviewer);
    }

    public function test_get_authorized_assignment_allows_matching_route_when_restricted() {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $routeid = $this->create_route('Erlaubte Route');
        $assignmentid = $this->create_assignment($user->id, $routeid);

        $railviewer = (object) ['routeid' => $routeid];

        $assignment = railviewer_get_authorized_assignment($assignmentid, $user->id, $railviewer);

        $this->assertEquals($assignmentid, $assignment->id);
    }

    public function test_get_authorized_assignment_generic_player_allows_any_own_route() {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $routea = $this->create_route('Route A');
        $routeb = $this->create_route('Route B');
        $assignmenta = $this->create_assignment($user->id, $routea);
        $assignmentb = $this->create_assignment($user->id, $routeb);

        // Genereller Player (routeid leer) - beide eigenen Zuweisungen müssen erreichbar sein,
        // unabhängig davon, welche Route.
        $railviewer = (object) ['routeid' => null];

        $this->assertEquals($assignmenta, railviewer_get_authorized_assignment($assignmenta, $user->id, $railviewer)->id);
        $this->assertEquals($assignmentb, railviewer_get_authorized_assignment($assignmentb, $user->id, $railviewer)->id);
    }

    // ------------------------------------------------------------------
    // railviewer_route_group_is_done()
    // ------------------------------------------------------------------

    public function test_route_group_is_done_single_open() {
        $group = ['type' => 'single', 'item' => ['remaining' => 1]];
        $this->assertFalse(railviewer_route_group_is_done($group));
    }

    public function test_route_group_is_done_single_closed() {
        $group = ['type' => 'single', 'item' => ['remaining' => 0]];
        $this->assertTrue(railviewer_route_group_is_done($group));
    }

    public function test_route_group_is_done_single_without_remaining_field_counts_as_open() {
        // Karten ohne 'remaining' (z.B. "nosections") gelten laut Dokumentation in lib.php immer
        // als offen - sie werden nie in den eingeklappten "Erledigt"-Bereich einsortiert.
        $group = ['type' => 'single', 'item' => ['nosections' => true]];
        $this->assertFalse(railviewer_route_group_is_done($group));
    }

    public function test_route_group_is_done_pair_requires_both_directions_done() {
        $group = [
            'type' => 'pair',
            'items' => [
                ['remaining' => 0],
                ['remaining' => 1],
            ],
        ];
        $this->assertFalse(railviewer_route_group_is_done($group), 'Ein einzelner offener Rest hält das ganze Paar offen');
    }

    public function test_route_group_is_done_pair_both_done() {
        $group = [
            'type' => 'pair',
            'items' => [
                ['remaining' => 0],
                ['remaining' => 0],
            ],
        ];
        $this->assertTrue(railviewer_route_group_is_done($group));
    }
}
