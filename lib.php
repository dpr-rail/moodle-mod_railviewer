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

defined('MOODLE_INTERNAL') || die();

function railviewer_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_ARCHETYPE: return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_MOD_INTRO: return true;
        case FEATURE_BACKUP_MOODLE2: return false;
        default: return null;
    }
}

function railviewer_add_instance($data, $mform) {
    global $DB;
    $data->timecreated = time();
    return $DB->insert_record('railviewer', $data);
}

function railviewer_update_instance($data, $mform) {
    global $DB;
    $data->timemodified = time();
    $data->id = $data->instance;
    return $DB->update_record('railviewer', $data);
}

function railviewer_delete_instance($id) {
    global $DB;
    return $DB->delete_records('railviewer', ['id' => $id]);
}

/**
 * Lädt die Zuweisung, die über eine bestimmte Aktivitätsinstanz gespielt werden darf, und
 * stellt dabei sicher, dass es wirklich die eigene Zuweisung des Nutzers ist.
 *
 * Eine Aktivitätsinstanz kann optional an genau eine Route gebunden sein (routeid gesetzt) -
 * dann darf über sie ausschließlich diese eine Route gespielt werden (z.B. wenn eine
 * Kursleitung eine Strecke gezielt in ein bestimmtes Kursthema einbetten will). Ist routeid
 * dagegen leer, ist die Aktivität ein "genereller" Player: der Nutzer kann darüber jede ihm
 * zugewiesene Route spielen, unabhängig davon, welche Route in dieser Aktivität hinterlegt
 * ist - das ist der Normalfall, da Zuweisungen in local_railvieweradmin unabhängig von
 * Kursaktivitäten vergeben werden und nicht für jede Route eine eigene Aktivität angelegt
 * werden soll. Die Bindung an $userid bleibt in beiden Fällen zwingend - eine fremde
 * assignmentid ist so nie erreichbar.
 *
 * @param int $assignmentid
 * @param int $userid
 * @param stdClass $railviewer Datensatz aus der railviewer-Tabelle (braucht ->routeid)
 * @return stdClass Wirft moodle_exception (via MUST_EXIST), falls keine passende Zuweisung existiert
 */
function railviewer_get_authorized_assignment($assignmentid, $userid, $railviewer) {
    global $DB;
    $conditions = ['id' => $assignmentid, 'userid' => $userid];
    if (!empty($railviewer->routeid)) {
        $conditions['routeid'] = $railviewer->routeid;
    }
    return $DB->get_record('railviewer_assignments', $conditions, '*', MUST_EXIST);
}

/**
 * Rendert eine einzelne Strecken-Karte (view.php und index.php verwenden dasselbe Markup/CSS,
 * daher hier zentral statt doppelt gepflegt).
 *
 * Erwartete Felder in $card: name (string), actionhtml (string, vorgerendert - Button oder
 * Hinweistext), sowie entweder nosections => true + nosectionstext, oder die vollständigen
 * Fortschrittsfelder: badgetext, badgeclass, percent, barclass, completed, required, remaining.
 *
 * @param array $card
 * @return string HTML
 */
function railviewer_render_route_card(array $card) {
    $cardclass = 'railviewer-route-card';
    if (isset($card['remaining']) && $card['remaining'] <= 0) {
        // Erledigte Strecken haben keinen dringenden Handlungsbedarf mehr - eigene Klasse für
        // eine dezentere Aktions-Schaltfläche (siehe styles_view.css).
        $cardclass .= ' railviewer-route-card-done';
    }
    $out = html_writer::start_div($cardclass);
    $out .= html_writer::start_div('railviewer-route-card-top');
    $out .= html_writer::tag('h3', '🛤️ ' . $card['name']);
    if (!empty($card['badgetext'])) {
        $out .= html_writer::tag('span', $card['badgetext'], ['class' => 'badge ' . $card['badgeclass'] . ' railviewer-route-badge']);
    }
    $out .= html_writer::end_div();

    if (!empty($card['nosections'])) {
        $out .= html_writer::div($card['nosectionstext'], 'text-muted');
    } else {
        $out .= html_writer::div('', 'railviewer-route-progress-bar ' . $card['barclass'],
            ['style' => '--railviewer-progress: ' . $card['percent'] . '%']);

        if (empty($card['hidevideometa'])) {
            $out .= html_writer::start_div('railviewer-route-meta');
            $out .= html_writer::tag('span', get_string('completedplaycounts', 'mod_railviewer') . ': ' . $card['completed'] . ' / ' . $card['required']);
            $out .= html_writer::tag('span', get_string('remainingplaycounts', 'mod_railviewer') . ': ' . ($card['videoremaining'] ?? $card['remaining']));
            $out .= html_writer::end_div();
        }
        if (!empty($card['extrahtml'])) {
            $out .= html_writer::div($card['extrahtml'], 'railviewer-route-extra');
        }
    }

    $out .= html_writer::div($card['actionhtml'], 'railviewer-route-actions');
    $out .= html_writer::end_div();
    return $out;
}

/**
 * Rendert die Karte für ein zusammengehöriges Hin-/Rückfahrt-Paar: ein umrahmter Container mit
 * kombiniertem Status/Fortschritt oben, darunter die zwei bestehenden Einzelkarten je Richtung
 * (jede Richtung behält ihren eigenen Abspiel-Button/assignmentid - nur die Anzeige wird
 * zusammengefasst, nie die Daten).
 *
 * @param array $cardhin Karten-Array der Hinfahrt (siehe railviewer_render_route_card())
 * @param array $cardrueck Karten-Array der Rückfahrt
 * @return string HTML
 */
function railviewer_render_route_pair_card(array $cardhin, array $cardrueck) {
    $hindone = isset($cardhin['remaining']) && $cardhin['remaining'] <= 0;
    $rueckdone = isset($cardrueck['remaining']) && $cardrueck['remaining'] <= 0;
    $bothdone = $hindone && $rueckdone;

    $combined = (object) [
        'completed' => ($cardhin['completed'] ?? 0) + ($cardrueck['completed'] ?? 0),
        'required' => ($cardhin['required'] ?? 0) + ($cardrueck['required'] ?? 0),
    ];

    $out = html_writer::start_div('railviewer-route-pair-card' . ($bothdone ? ' railviewer-route-pair-card-done' : ''));
    $out .= html_writer::start_div('railviewer-route-pair-header');
    $out .= html_writer::tag('h3', '🔁 ' . get_string('roundtrip_label', 'mod_railviewer'));
    if (!empty($cardhin['learning']) || !empty($cardrueck['learning'])) {
        // Lernmethoden: nicht Videodurchläufe summieren, sondern erledigte Richtungen zählen.
        $badge = get_string('roundtrip_done_count', 'local_railvieweradmin',
            (object) ['done' => (int) $hindone + (int) $rueckdone, 'total' => 2]);
    } else {
        $badge = get_string('roundtrip_combined_progress', 'mod_railviewer', $combined);
    }
    $out .= html_writer::tag('span', $badge,
        ['class' => 'badge ' . ($bothdone ? 'badge-success' : 'badge-warning') . ' railviewer-route-badge']);
    $out .= html_writer::end_div();

    $out .= html_writer::start_div('railviewer-route-pair-body');
    $out .= railviewer_render_route_card($cardhin);
    $out .= railviewer_render_route_card($cardrueck);
    $out .= html_writer::end_div();
    $out .= html_writer::end_div();

    return $out;
}

/**
 * Rendert eine Gruppe (siehe local_railvieweradmin_group_by_route_pairing()): eine einzelne
 * Karte oder eine Hin-/Rückfahrt-Paar-Karte.
 */
function railviewer_render_route_group(array $group) {
    if ($group['type'] === 'pair') {
        return railviewer_render_route_pair_card($group['items'][0], $group['items'][1]);
    }
    return railviewer_render_route_card($group['item']);
}

/**
 * Ob eine Gruppe als "erledigt" gilt - bei einem Paar nur, wenn BEIDE Richtungen
 * abgeschlossen sind, ein einzelner offener Rest hält das ganze Paar in der offenen Liste.
 */
function railviewer_route_group_is_done(array $group) {
    if ($group['type'] === 'single') {
        $card = $group['item'];
        return isset($card['remaining']) && $card['remaining'] <= 0;
    }
    foreach ($group['items'] as $card) {
        if (!isset($card['remaining']) || $card['remaining'] > 0) {
            return false;
        }
    }
    return true;
}

/**
 * Rendert ein Karten-Raster: offene/in Bearbeitung befindliche Strecken(-Paare) direkt sichtbar,
 * erledigte in einem eigenen, standardmäßig eingeklappten Bereich darunter - der Nutzer soll
 * primär sehen, was noch zu tun ist, ohne dass bereits abgeschlossene Strecken (die Historie
 * dokumentiert sie ohnehin separat) den Überblick zukleistern.
 *
 * @param array $groups Ergebnis von local_railvieweradmin_group_by_route_pairing() über die
 *   Karten-Arrays (die dafür routeid/pairedrouteid/direction mitführen müssen, siehe
 *   railviewer_render_route_card()). Karten ohne 'remaining'-Feld (nosections) gelten als
 *   "offen" und werden nie eingeklappt.
 * @return string HTML
 */
function railviewer_render_route_grid(array $groups) {
    $open = array_filter($groups, function($g) { return !railviewer_route_group_is_done($g); });
    $done = array_filter($groups, 'railviewer_route_group_is_done');

    $out = html_writer::start_div('railviewer-route-grid');
    foreach ($open as $group) {
        $out .= railviewer_render_route_group($group);
    }
    $out .= html_writer::end_div();

    if (!empty($done)) {
        $out .= html_writer::tag('hr', '', ['class' => 'railviewer-done-divider']);
        $out .= html_writer::start_tag('details', ['class' => 'railviewer-done-section']);
        $out .= html_writer::tag('summary', get_string('completed_routes_heading', 'mod_railviewer', count($done)),
            ['class' => 'railviewer-done-summary']);
        $out .= html_writer::start_div('railviewer-route-grid');
        foreach ($done as $group) {
            $out .= railviewer_render_route_group($group);
        }
        $out .= html_writer::end_div();
        $out .= html_writer::end_tag('details');
    }

    return $out;
}

/**
 * Lernmethoden-Feature: passt eine Streckenkarte (view.php/index.php) an die Gesamtvollständigkeit
 * einer Zuweisung an. Ohne aktives Feature bleibt die Karte unverändert (reines Video-Zählen).
 *
 * Mit Feature: Status/Badge nach Gesamterfüllung (Video + alle Abschnitte x Methoden, erlassen =
 * "Erlassen"), Fortschrittsbalken nach Sollzeit statt nur Video, Video-Zähler nur wenn Video
 * gefordert ist, darunter "Es fehlt noch …", Sollzeit und Link zu den Lernfahrten.
 *
 * @param array $card Karten-Array (siehe railviewer_render_route_card())
 * @param stdClass $assignment Datensatz aus railviewer_assignments
 * @return array angepasste Karte
 */
function railviewer_apply_learning_to_card(array $card, $assignment) {
    if (!\local_railvieweradmin\learning::enabled()) {
        return $card;
    }
    $status = \local_railvieweradmin\learning::status($assignment);
    $duration = \local_railvieweradmin\learning::duration($assignment, $status);

    $card['learning'] = true;
    $card['videoremaining'] = $card['remaining'] ?? 0;
    $card['hidevideometa'] = (int) $assignment->required_views <= 0;
    $complete = $status['complete'];
    // 'remaining' steuert Einklappen/Erledigt-Optik: 0 nur bei vollständig erfüllter Strecke.
    $card['remaining'] = $complete ? 0 : max(1, (int) $card['videoremaining']);

    $anyprogress = ((int) $assignment->completed_views > 0) || $duration['ist'] > 0;
    if (!empty($assignment->waived)) {
        $card['badgetext'] = get_string('waived_label', 'local_railvieweradmin');
        $card['badgeclass'] = 'badge-secondary';
        $card['barclass'] = 'railviewer-route-progress-complete';
        $card['percent'] = 100;
    } else if ($complete) {
        $card['badgetext'] = get_string('status_erledigt', 'mod_railviewer');
        $card['badgeclass'] = 'badge-success';
        $card['barclass'] = 'railviewer-route-progress-complete';
        $card['percent'] = 100;
    } else {
        $card['badgetext'] = get_string($anyprogress ? 'status_in_bearbeitung' : 'status_offen', 'mod_railviewer');
        $card['badgeclass'] = $anyprogress ? 'badge-warning' : 'badge-secondary';
        $card['barclass'] = $anyprogress ? 'railviewer-route-progress-partial' : '';
        if ($duration['soll'] > 0) {
            $card['percent'] = min(99, (int) round(100 * $duration['ist'] / $duration['soll']));
        }
    }

    $extra = '';
    if (!empty($assignment->waived)) {
        $extra .= html_writer::div(s((string) $assignment->waivereason), 'text-muted small');
    } else {
        $extra .= \local_railvieweradmin\ui::duration_line($duration);
        if (!$complete && $status['missing']) {
            $parts = [];
            foreach (array_slice($status['missing'], 0, 3) as $m) {
                $parts[] = $m['sectionname'] . ' – ' . $m['count'] . '× ' . $m['methodname'];
            }
            $more = count($status['missing']) > 3 ? ' …' : '';
            $extra .= html_writer::div(get_string('still_missing', 'local_railvieweradmin') . ': '
                . s(implode('; ', $parts)) . $more, 'text-muted small');
        }
        if ($status['methods']) {
            $extra .= html_writer::div(html_writer::link(new moodle_url('/mod/railviewer/rides.php'),
                '🚆 ' . get_string('my_rides', 'local_railvieweradmin'), ['class' => 'btn btn-sm btn-outline-primary mt-1']));
        }
    }
    $card['extrahtml'] = $extra;
    return $card;
}
