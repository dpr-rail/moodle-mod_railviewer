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
require_login();

$id = required_param('id', PARAM_INT); // Course module ID
$cm = get_coursemodule_from_id('railviewer', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('mod/railviewer:view', $context);

$PAGE->set_cm($cm, $course);
$PAGE->set_context($context);
$mapurlparams = ['id' => $id];
if (optional_param('userid', 0, PARAM_INT)) {
    $mapurlparams['userid'] = optional_param('userid', 0, PARAM_INT);
}
$PAGE->set_url('/mod/railviewer/map.php', $mapurlparams);
$PAGE->set_title(format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));

$railviewer = $DB->get_record('railviewer', ['id' => $cm->instance], '*', MUST_EXIST);

// Admin-Ansicht: ein Railviewer-Admin darf sich die Karte eines ANDEREN Nutzers rein lesend
// ansehen (Link von assignments.php), ohne sich per "Als Nutzer anmelden" tatsächlich dessen
// Identität anzunehmen. Nur erlaubt mit lokaler Manage-Capability (Systemkontext, siehe
// db/access.php lokal - NICHT dieselbe wie mod/railviewer:view oben, das prüft nur den
// Zugriff auf DIESE Aktivität, nicht die Berechtigung, fremde Fortschritte einzusehen).
global $USER;
$viewuserid = optional_param('userid', 0, PARAM_INT);
$isadminview = false;
if ($viewuserid && $viewuserid != $USER->id) {
    require_capability('local/railvieweradmin:editsections', context_system::instance());
    $targetuser = $DB->get_record('user', ['id' => $viewuserid], '*', MUST_EXIST);
    $targetuserid = $targetuser->id;
    $isadminview = true;
} else {
    $targetuserid = $USER->id;
}

// Cache-Busting anhand des Datei-Zeitstempels, analog zu streckenmap.php (lokale
// <script src="...">/<link>-Einbindung statt Theme-Revision-Mechanismus).
$assetversion = function (string $relpath): moodle_url {
    $fullpath = $GLOBALS['CFG']->dirroot . '/local/railvieweradmin' . $relpath;
    $rev = is_file($fullpath) ? filemtime($fullpath) : 0;
    return new moodle_url('/local/railvieweradmin' . $relpath, ['v' => $rev]);
};

// Muss vor $OUTPUT->header() passieren - Moodle druckt <head> (inkl. aller CSS-Requires)
// bereits beim header()-Aufruf, danach nachträglich requires->css() aufzurufen wirft
// "Cannot require a CSS file after <head> has been printed."
$PAGE->requires->css($assetversion('/thirdpartylibs/leaflet/leaflet.css'));
$PAGE->requires->css($assetversion('/streckenmap/style.css'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('streckenmap', 'mod_railviewer'));

if ($isadminview) {
    echo $OUTPUT->notification(
        get_string('adminview_notice', 'mod_railviewer', fullname($targetuser)),
        \core\output\notification::NOTIFY_INFO
    );
    // "Zurück zur Listenansicht" würde hier verwirren - view.php unterstützt die
    // Admin-Ansicht (bewusst) nicht und würde wieder die eigenen Zuweisungen des Admins
    // zeigen. Stattdessen zurück dorthin, wo dieser Link herkam.
    echo html_writer::link(new moodle_url('/local/railvieweradmin/assignments.php'),
        '« ' . get_string('back_to_assignments', 'mod_railviewer'));
} else {
    echo html_writer::link(new moodle_url('/mod/railviewer/view.php', ['id' => $id]),
        '« ' . get_string('back_to_listview', 'mod_railviewer'));
}

if (empty(get_config('local_railvieweradmin', 'map_enabled'))) {
    echo $OUTPUT->notification(get_string('map_disabled_notice', 'local_railvieweradmin'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$tileurl = get_config('local_railvieweradmin', 'map_tile_url');
if (empty($tileurl)) {
    echo $OUTPUT->notification(get_string('map_no_tileurl_notice', 'local_railvieweradmin'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

global $USER;

// Wie bei view.php: ohne an diese Aktivität gebundene Route (Normalfall) werden ALLE dem
// Nutzer zugewiesenen Routen auf der Karte gezeigt, nicht nur eine einzelne.
if (!empty($railviewer->routeid)) {
    $assignments = $DB->get_records('railviewer_assignments', ['userid' => $targetuserid, 'routeid' => $railviewer->routeid]);
} else {
    $assignments = $DB->get_records('railviewer_assignments', ['userid' => $targetuserid]);
}
if (!$assignments) {
    echo $OUTPUT->notification(get_string('noroutesassigned', 'mod_railviewer'));
    echo $OUTPUT->footer();
    exit;
}

$tileattribution = get_config('local_railvieweradmin', 'map_tile_attribution');
$tilemaxzoom = (int) get_config('local_railvieweradmin', 'map_tile_max_zoom') ?: 19;

// Für jede Zuweisung dieser Route die geo-verknüpften Abschnitte einsammeln und in die
// Struktur für Karte + gruppierte Aufgabenliste überführen. Vereinfachung (dokumentiert im
// Implementierungsplan): Erscheint ein Abschnitt in mehreren Zuweisungen gleichzeitig, zählt
// die zuerst gefundene, nicht abgeschlossene Zuweisung.
$edgeprogress = [];  // directionId -> {assignment, views}
$nodeprogress = []; // ril100 -> {assignment, views}
// Gruppierungs-Schlüssel -> ['label' => ..., 'hin' => [...], 'rueck' => [...], 'ungrouped' => [...]].
// Ersetzt die frühere, rein zufällige Gruppierung nach GIS-streckennummer (nur für geo-
// verknüpfte Abschnitte vorhanden) durch die explizite Hin-/Rückfahrt-Streckenpaarung -
// funktioniert jetzt auch für nicht geo-verknüpfte Strecken, die vorher in der Aufgabenliste
// komplett unsichtbar waren.
$tasksbyroutepair = [];
$ortskenntnistasks = [];
$seennongeosections = [];

foreach ($assignments as $assignment) {
    // Kein location_type-Filter mehr: der Karten-Layer selbst bleibt weiter unten geo-only
    // (nur 'edge'/'node' erzeugen ein Layer-Element), aber die Aufgabenliste soll jetzt auch
    // rein namensbasierte, nicht geo-verknüpfte Abschnitte zeigen (sonst über den else-Zweig
    // unten komplett unsichtbar).
    $route = $DB->get_record('railviewer_routes', ['id' => $assignment->routeid]);
    if (!$route) {
        continue;
    }

    if (!empty($route->pairedrouteid)) {
        $groupkey = 'pair-' . min($route->id, $route->pairedrouteid) . '-' . max($route->id, $route->pairedrouteid);
        $bucket = ($route->direction === 'rueck') ? 'rueck' : 'hin';
        if ($route->direction === 'hin') {
            $grouplabel = format_string($route->name);
        } else {
            $hinname = $DB->get_field('railviewer_routes', 'name', ['id' => $route->pairedrouteid]);
            $grouplabel = format_string($hinname !== false ? $hinname : $route->name);
        }
    } else {
        $groupkey = 'route-' . $route->id;
        $bucket = 'ungrouped';
        $grouplabel = format_string($route->name);
    }
    if (!isset($tasksbyroutepair[$groupkey])) {
        $tasksbyroutepair[$groupkey] = ['label' => $grouplabel, 'hin' => [], 'rueck' => [], 'ungrouped' => []];
    }

    $sections = $DB->get_records_sql("
        SELECT s.*
          FROM {railviewer_route_sections} rs
          JOIN {railviewer_sections} s ON s.id = rs.sectionid
         WHERE rs.routeid = ?
        ORDER BY rs.sortorder ASC
    ", [$assignment->routeid]);

    foreach ($sections as $section) {
        $views = $DB->count_records('railviewer_views', [
            'assignmentid' => $assignment->id,
            'userid' => $targetuserid,
            'sectionid' => $section->id,
        ]);
        $iscomplete = $views >= $assignment->required_views;

        $payload = [
            'sectionid' => (int) $section->id,
            'assignmentid' => (int) $assignment->id,
            'requiredViews' => (int) $assignment->required_views,
            'title' => $section->name,
        ];

        $taskitem = [
            'title' => $section->name,
            'views' => (int) $views,
            'required' => (int) $assignment->required_views,
            'complete' => $iscomplete,
            'assignmentid' => (int) $assignment->id,
            'cmid' => (int) $cm->id,
        ];

        if ($section->location_type === 'edge') {
            $edges = $DB->get_records('railviewer_section_edges', ['sectionid' => $section->id], 'sortorder ASC');
            if (!$edges) {
                continue;
            }
            $first = reset($edges);

            // Bereits über eine andere (frühere) Zuweisung erfasste Kanten nicht erneut mit
            // ggf. schlechterem Fortschritt überschreiben.
            $alreadydone = false;
            foreach ($edges as $edge) {
                $dirid = $edge->edge_id . '__' . $edge->direction;
                if (isset($edgeprogress[$dirid])) {
                    $alreadydone = true;
                    break;
                }
            }
            if ($alreadydone) {
                continue;
            }
            foreach ($edges as $edge) {
                $dirid = $edge->edge_id . '__' . $edge->direction;
                $edgeprogress[$dirid] = ['assignment' => $payload, 'views' => $views];
            }

            $tasksbyroutepair[$groupkey][$bucket][] = $taskitem;
        } else if ($section->location_type === 'node' && !empty($section->node_ril100)) {
            // Ortskenntnis-Punkte sind bewusst NICHT Teil der Hin-/Rückfahrt-Gruppierung - ein
            // einzelner Punkt hat keine Fahrtrichtung, bleibt wie bisher eine eigene Liste.
            if (isset($nodeprogress[$section->node_ril100])) {
                continue;
            }
            $nodeprogress[$section->node_ril100] = ['assignment' => $payload, 'views' => $views];
            $ortskenntnistasks[] = $taskitem;
        } else {
            // Nicht geo-verknüpfter Abschnitt (location_type leer/NULL): kein Karten-Layer-
            // Eintrag möglich (nichts zu zeichnen), aber jetzt trotzdem in der gruppierten
            // Aufgabenliste sichtbar statt vorher komplett zu fehlen.
            if (isset($seennongeosections[$section->id])) {
                continue;
            }
            $seennongeosections[$section->id] = true;
            $tasksbyroutepair[$groupkey]['ungrouped'][] = $taskitem;
        }
    }
}

if (empty($edgeprogress) && empty($nodeprogress)) {
    echo $OUTPUT->notification(get_string('map_no_geo_sections_notice', 'local_railvieweradmin'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

ksort($tasksbyroutepair);

?>
<div class="railviewer-map-layout">
  <div id="railviewer-usermap" class="railviewer-map-canvas"></div>
  <div class="railviewer-map-sidebar">
    <h3><?php echo get_string('streckenmap_legend_heading', 'mod_railviewer'); ?></h3>
    <div class="railviewer-map-legend">
      <div class="railviewer-map-legend-item"><span class="railviewer-map-legend-swatch" style="background:#c9ccd1;"></span><?php echo get_string('status_none', 'mod_railviewer'); ?></div>
      <div class="railviewer-map-legend-item"><span class="railviewer-map-legend-swatch" style="background:#3388ff;"></span><?php echo get_string('status_offen', 'mod_railviewer'); ?></div>
      <div class="railviewer-map-legend-item"><span class="railviewer-map-legend-swatch" style="background:#f5a623;"></span><?php echo get_string('status_in_bearbeitung', 'mod_railviewer'); ?></div>
      <div class="railviewer-map-legend-item"><span class="railviewer-map-legend-swatch" style="background:#2ecc71;"></span><?php echo get_string('status_erledigt', 'mod_railviewer'); ?></div>
    </div>
  </div>
</div>

<button type="button" id="railviewer-tasklist-toggle" class="railviewer-map-tasklist-toggle">
    <?php echo get_string('show_tasklist', 'mod_railviewer'); ?> ▾
</button>
<div id="railviewer-tasklist" class="railviewer-map-tasklist" hidden></div>

<script src="<?php echo $assetversion('/thirdpartylibs/leaflet/leaflet.js')->out(false); ?>"></script>
<script src="<?php echo $assetversion('/streckenmap/offset-lines.js')->out(false); ?>"></script>
<script src="<?php echo $assetversion('/streckenmap/map-widget.js')->out(false); ?>"></script>
<script>
(function() {
    const wwwroot = <?php echo json_encode($CFG->wwwroot); ?>;
    const cmid = <?php echo (int) $cm->id; ?>;
    const isAdminView = <?php echo json_encode($isadminview); ?>;
    const labels = {
        hinfahrt: <?php echo json_encode(get_string('direction_hin', 'local_railvieweradmin')); ?>,
        rueckfahrt: <?php echo json_encode(get_string('direction_rueck', 'local_railvieweradmin')); ?>,
        strecke: <?php echo json_encode(get_string('route_label_short', 'local_railvieweradmin')); ?>,
        ortskenntnis: <?php echo json_encode(get_string('ortskenntnis', 'local_railvieweradmin')); ?>,
        progress: <?php echo json_encode(get_string('progress_tooltip_template', 'mod_railviewer')); ?>,
        progressDone: <?php echo json_encode(get_string('progress_tooltip_done_template', 'mod_railviewer')); ?>,
    };
    const edgeProgress = <?php echo json_encode($edgeprogress); ?>;
    const nodeProgress = <?php echo json_encode($nodeprogress); ?>;

    const widget = new StreckenMapWidget('railviewer-usermap', {
        mode: 'user',
        tileUrl: <?php echo json_encode($tileurl); ?>,
        tileAttribution: <?php echo json_encode($tileattribution); ?>,
        maxZoom: <?php echo (int) $tilemaxzoom; ?>,
        labels: labels,
    });

    function openVideo(assignment) {
        // In der Admin-Ansicht nicht abspielbar: play.php autorisiert Zuweisungen anhand des
        // TATSÄCHLICH eingeloggten Nutzers (siehe railviewer_get_authorized_assignment()) - ein
        // Klick würde hier immer die Zuweisungen des Admins prüfen, nicht die des betrachteten
        // Nutzers, und entweder fehlschlagen oder (schlimmer) versehentlich eine fremde
        // Zuweisung des Admins selbst öffnen.
        if (isAdminView) {
            return;
        }
        window.location.href = wwwroot + '/mod/railviewer/play.php?assignmentid=' + assignment.assignmentid + '&cmid=' + cmid;
    }
    widget.onVideoOpen = function(dl, assignment) { openVideo(assignment); };
    widget.onNodeVideoOpen = function(feature, assignment) { openVideo(assignment); };

    function loadJson(url) {
        return fetch(url, {credentials: 'same-origin'}).then(function(r) { return r.json(); });
    }

    Promise.all([
        loadJson(wwwroot + '/local/railvieweradmin/ajax/get_edges.php'),
        loadJson(wwwroot + '/local/railvieweradmin/ajax/get_nodes.php'),
    ]).then(function(results) {
        widget.loadEdges(results[0]);
        widget.loadNodes(results[1]);

        Object.keys(edgeProgress).forEach(function(dirid) {
            widget.setUserProgress(dirid, edgeProgress[dirid].assignment, edgeProgress[dirid].views);
        });
        Object.keys(nodeProgress).forEach(function(ril100) {
            widget.setNodeUserProgress(ril100, nodeProgress[ril100].assignment, nodeProgress[ril100].views);
        });
    }).catch(function(err) {
        // Echte Fehlermeldung mit ausgeben statt nur den generischen Text - siehe
        // streckenmap.php für dasselbe Muster in der Admin-Kartenansicht.
        window.console && console.error('Streckenkarte: Laden fehlgeschlagen', err);
        var detail = err && err.message ? err.message : String(err);
        document.getElementById('railviewer-usermap').innerHTML =
            '<div class="alert alert-danger"><?php echo get_string('generic_ajax_error', 'local_railvieweradmin'); ?><br><small>' +
            detail.replace(/[<>&]/g, function(c) { return {'<':'&lt;','>':'&gt;','&':'&amp;'}[c]; }) +
            '</small></div>';
    });

    // Gruppierte Aufgabenliste: standardmäßig ausgeblendet, per Umschalter ein-/ausblendbar
    // (siehe Implementierungsplan). Zustand wird zusätzlich in localStorage gemerkt.
    const tasksByRoutePair = <?php echo json_encode($tasksbyroutepair); ?>;
    const ortskenntnisTasks = <?php echo json_encode($ortskenntnistasks); ?>;
    const tasklistEl = document.getElementById('railviewer-tasklist');
    const toggleBtn = document.getElementById('railviewer-tasklist-toggle');

    function taskItemHtml(task) {
        const color = task.complete ? '#2ecc71' : (task.views > 0 ? '#f5a623' : '#3388ff');
        const div = document.createElement('div');
        div.className = 'railviewer-map-task-item';
        div.tabIndex = 0;
        div.setAttribute('role', 'button');
        div.innerHTML = '<span><span class="railviewer-map-task-status" style="background:' + color + ';"></span> ' +
            escapeHtml(task.title) + '</span><span>' + (task.complete ? '✓ ' : '') + task.views + ' / ' + task.required + '</span>';
        div.addEventListener('click', function() { openVideo(task); });
        div.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openVideo(task); }
        });
        return div;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function groupSummary(tasks) {
        const done = tasks.filter(function(t) { return t.complete; }).length;
        return done + ' / ' + tasks.length;
    }

    function buildTaskList() {
        tasklistEl.innerHTML = '';

        Object.keys(tasksByRoutePair).forEach(function(groupkey) {
            const group = tasksByRoutePair[groupkey];
            const alltasks = group.hin.concat(group.rueck, group.ungrouped);
            if (!alltasks.length) return;

            const details = document.createElement('details');
            details.className = 'railviewer-map-tasklist-group';
            const summary = document.createElement('summary');
            summary.textContent = group.label + ' – ' + groupSummary(alltasks);
            details.appendChild(summary);

            // Hin-/Rückfahrt-Unterlisten und die ungruppierte Liste (nicht geo-verknüpfte
            // Abschnitte, ohne eigene Fahrtrichtung) schließen sich NICHT gegenseitig aus -
            // eine verknüpfte Strecke kann z.B. geo-verknüpfte UND rein namensbasierte
            // Abschnitte gleichzeitig enthalten, dann erscheinen beide Unterlisten.
            [['hin', labels.hinfahrt], ['rueck', labels.rueckfahrt]].forEach(function(pair) {
                const tasks = group[pair[0]];
                if (!tasks.length) return;
                const sub = document.createElement('div');
                sub.className = 'railviewer-map-tasklist-subgroup';
                const h4 = document.createElement('h4');
                h4.textContent = pair[1] + ' (' + groupSummary(tasks) + ')';
                sub.appendChild(h4);
                tasks.forEach(function(t) { sub.appendChild(taskItemHtml(t)); });
                details.appendChild(sub);
            });

            if (group.ungrouped.length) {
                // Flache Liste ohne Hin-/Rückfahrt-Unterüberschrift (keine Gegenrichtung, oder
                // nicht geo-verknüpft).
                const sub = document.createElement('div');
                sub.className = 'railviewer-map-tasklist-subgroup';
                group.ungrouped.forEach(function(t) { sub.appendChild(taskItemHtml(t)); });
                details.appendChild(sub);
            }

            tasklistEl.appendChild(details);
        });

        if (ortskenntnisTasks.length) {
            const details = document.createElement('details');
            details.className = 'railviewer-map-tasklist-group';
            const summary = document.createElement('summary');
            summary.textContent = labels.ortskenntnis + ' – ' + groupSummary(ortskenntnisTasks);
            details.appendChild(summary);
            const sub = document.createElement('div');
            sub.className = 'railviewer-map-tasklist-subgroup';
            ortskenntnisTasks.forEach(function(t) { sub.appendChild(taskItemHtml(t)); });
            details.appendChild(sub);
            tasklistEl.appendChild(details);
        }
    }

    buildTaskList();

    const storagekey = 'railviewer_tasklist_visible_' + cmid;
    function setVisible(visible) {
        tasklistEl.hidden = !visible;
        toggleBtn.textContent = (visible
            ? <?php echo json_encode(get_string('hide_tasklist', 'mod_railviewer')); ?> + ' ▴'
            : <?php echo json_encode(get_string('show_tasklist', 'mod_railviewer')); ?> + ' ▾');
        try { localStorage.setItem(storagekey, visible ? '1' : '0'); } catch (e) { /* ignore */ }
    }

    let initiallyvisible = false;
    try { initiallyvisible = localStorage.getItem(storagekey) === '1'; } catch (e) { /* ignore */ }
    setVisible(initiallyvisible);

    toggleBtn.addEventListener('click', function() {
        setVisible(tasklistEl.hidden);
    });
})();
</script>
<?php

echo $OUTPUT->footer();
