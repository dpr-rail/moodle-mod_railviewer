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
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/local/railvieweradmin/lib.php');
require_once($CFG->dirroot . '/mod/railviewer/lib.php');
require_login();

$assignmentid = required_param('assignmentid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);

global $DB, $USER, $PAGE, $OUTPUT;

$cm = get_coursemodule_from_id('railviewer', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('mod/railviewer:view', $context);

$railviewer = $DB->get_record('railviewer', ['id' => $cm->instance], '*', MUST_EXIST);

// Nur die eigene Zuweisung ist erreichbar; ist diese Aktivität an eine bestimmte Route
// gebunden, muss die Zuweisung zusätzlich zu dieser Route gehören (siehe
// railviewer_get_authorized_assignment()).
$assignment = railviewer_get_authorized_assignment($assignmentid, $USER->id, $railviewer);

// Bereits abgeschlossene Zuweisungen werden NICHT mehr weggeleitet - die "Erneut ansehen"-
// Karten auf view.php/index.php verlinken bewusst genau hierher, damit erledigte Strecken
// zu Wiederholungs-/Kontrollzwecken erneut angesehen werden können. Ein zusätzlicher Play-
// Durchlauf erhöht completed_views weiter, was für die Fortschrittsanzeige unschädlich ist
// (überall dort mit max(0, required - completed) gegen negative Restanzahlen abgesichert).
$isreview = $assignment->completed_views >= $assignment->required_views;

// Hole alle Abschnitte der Route, sortiert nach Reihenfolge
$sectionids = $DB->get_records_sql_menu("
    SELECT sectionid, sortorder
    FROM {railviewer_route_sections}
    WHERE routeid = ?
    ORDER BY sortorder ASC
", [$assignment->routeid]);

$sections = [];
foreach ($sectionids as $sectionid => $sortorder) {
    $section = $DB->get_record('railviewer_sections', ['id' => $sectionid]);
    if ($section) {
        $sections[] = $section;
    }
}

$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_url(new moodle_url('/mod/railviewer/play.php', ['assignmentid' => $assignmentid, 'cmid' => $cmid]));
$PAGE->set_title(get_string('videotitle', 'mod_railviewer'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

$routename = $DB->get_field('railviewer_routes', 'name', ['id' => $assignment->routeid]);
echo html_writer::tag('h3', get_string('route', 'mod_railviewer') . ': ' . format_string($routename));

if ($isreview) {
    echo $OUTPUT->notification(get_string('reviewmode_notice', 'mod_railviewer'), \core\output\notification::NOTIFY_INFO);
}

if (!$sections) {
    echo html_writer::div(get_string('nosectionsfound', 'mod_railviewer'), 'alert alert-danger');
    echo $OUTPUT->footer();
    exit;
}

// Ermittle die höchste playcountid für dieses Assignment
$last_play = $DB->get_record_sql("
    SELECT MAX(playcountid) AS maxid FROM {railviewer_views}
    WHERE assignmentid = ? AND userid = ?
", [$assignmentid, $USER->id]);

$playcountid = ($last_play && $last_play->maxid) ? $last_play->maxid : 1;

// Prüfe, ob für diese playcountid bereits ein Marker mit sectionid = -1 vorhanden ist
$marker_exists = $DB->record_exists('railviewer_views', [
    'assignmentid' => $assignmentid,
    'userid' => $USER->id,
    'playcountid' => $playcountid,
    'sectionid' => -1,
]);


if ($marker_exists) {
    $playcountid += 1;
}

// Lade Videos, Overlays und Ebula-Daten pro Abschnitt
// Ganzes URL-Muster (nicht nur die Domain) konfigurierbar, mit {id}-Platzhalter - siehe
// settings.php.
$ignite_embed_url_template = get_config('local_railvieweradmin', 'ignite_embed_url_template')
    ?: 'https://play.ignite.video/player/index.html?id={id}&controls=false';

// Verfügbare Geschwindigkeitsstufen bis zur vom Admin erlaubten Obergrenze (siehe Settings).
// Nur bei Upload/Vimeo nutzbar - die player.js-Bibliothek von ignite.video kennt keine
// setPlaybackRate()-Methode (offizielle Spec: play/pause/mute/volume/currentTime/loop, siehe
// https://github.com/embedly/player.js - keine Geschwindigkeitskontrolle vorgesehen).
$all_speed_steps = [1, 1.25, 1.5, 2, 3, 4, 6, 8];
$max_playback_speed = (float) (get_config('local_railvieweradmin', 'max_playback_speed') ?: 1);
$available_speeds = array_values(array_filter($all_speed_steps, function($s) use ($max_playback_speed) {
    return $s <= $max_playback_speed + 0.001; // Fließkomma-Toleranz
}));
if (empty($available_speeds)) {
    $available_speeds = [1];
}

// get_config() liefert false zurück, solange die Einstellungsseite noch nie gespeichert wurde
// (der im Formular hinterlegte Default-Wert "1" wird erst beim ersten Speichern in die DB
// geschrieben) - ohne diese Sonderbehandlung wäre der Button direkt nach dem Update dieses
// Plugins fälschlich ausgeblendet, bis ein Admin die Einstellungen einmal öffnet und speichert.
$show_mute_button_raw = get_config('local_railvieweradmin', 'show_mute_button');
$show_mute_button = $show_mute_button_raw === false ? true : (bool) $show_mute_button_raw;

// Merken, ob überhaupt ein Abschnitt dieser Route Vimeo/ignite.video nutzt - die Player-Skripte
// unten werden NUR bei Bedarf geladen (nicht unconditional wie zuvor), analog zur Kopplung an
// video_source in ebula_section_map.php/edit_overlay.php. Route-weit statt pro Abschnitt, da
// über die Vorherige/Nächste-Buttons zwischen Abschnitten mit unterschiedlichem Anbieter
// gewechselt werden kann, ohne die Seite neu zu laden.
$routeusesvimeo = false;
$routeusesignite = false;

$video_data = [];
foreach ($sections as $section) {
    // Videoquelle bestimmen. video_source ist die maßgebliche Angabe aus der Abschnitts-
    // verwaltung; bei älteren, vor Einführung dieses Felds angelegten Abschnitten wird
    // ersatzweise anhand der vorhandenen ID/Datei geraten (Rückwärtskompatibilität).
    $video_source = $section->video_source ?? null;
    if (empty($video_source)) {
        if (!empty($section->vimeo_video_id)) {
            $video_source = 'vimeo';
        } else if (!empty($section->ignite_video_id)) {
            $video_source = 'ignite';
        } else if (!empty($section->sharepoint_item_id)) {
            $video_source = 'sharepoint';
        } else if (!empty($section->s3_object_key)) {
            $video_source = 's3';
        } else {
            $video_source = 'upload';
        }
    }

    $videourl = null;
    $vimeo_id = null;
    $ignite_id = null;

    if ($video_source === 'vimeo') {
        $vimeo_id = $section->vimeo_video_id;
        $routeusesvimeo = true;
    } else if ($video_source === 'ignite') {
        $ignite_id = $section->ignite_video_id;
        $routeusesignite = true;
    } else if ($video_source === 's3' && !empty($section->s3_object_key)) {
        // Signierte, zeitlich begrenzte URL (Bucket bleibt privat); wird vom generischen
        // nativen <video>-Codepfad genau wie eine hochgeladene Datei abgespielt.
        $s3 = new \local_railvieweradmin\s3_client();
        if ($s3->is_configured() && \local_railvieweradmin\s3_client::is_valid_key($section->s3_object_key)) {
            $videourl = new moodle_url($s3->presign_get($section->s3_object_key));
        }
    } else if ($video_source === 'sharepoint' && !empty($section->sharepoint_item_id)) {
        // Kein eigener Player-Zweig nötig: der generische native <video>-Codepfad unten
        // (section.videourl, siehe loadSection() im Script) behandelt SharePoint-Videos exakt
        // wie hochgeladene Dateien - nur die URL zeigt auf sharepoint_stream.php statt auf
        // pluginfile.php (siehe dortiger Kommentar, warum das für SharePoint reicht/nötig ist).
        $videourl = new moodle_url('/local/railvieweradmin/sharepoint_stream.php', ['sectionid' => $section->id]);
    } else {
        $files = $fs = get_file_storage()->get_area_files($context->id, 'local_railvieweradmin', 'sectionvideo', $section->id, 'sortorder', false);
        if ($files) {
            $file = reset($files);
            $videourl = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename(),
                false
            );
        }
    }

    // Overlays laden
    $overlays = $DB->get_records('railviewer_overlays', ['sectionid' => $section->id]);
    // Eigenständige Stopps (railviewer_stops) laden - pausieren das Video an fester Zeit.
    $stops = $DB->get_records('railviewer_stops', ['sectionid' => $section->id], 'stoptime ASC');
    $formatted_stops = array_values(array_map(function($stop) {
        return (object)[
            'id' => $stop->id,
            'name' => $stop->name,
            'stoptime' => $stop->stoptime,
        ];
    }, $stops));

    // Ebula-Daten laden
    $ebula_points = $DB->get_records('railviewer_ebula_section_map', ['sectionid' => $section->id], 'videotime ASC');
    $ebula_timetable = $DB->get_record('railviewer_ebula_section', ['sectionid' => $section->id]);
    
    $video_data[] = [
        'sectionid' => $section->id,
        'name' => $section->name,
        'video_source' => $video_source,
        'videourl' => $videourl ? $videourl->out(false) : null,
        'vimeo_id' => $vimeo_id,
        'ignite_id' => $ignite_id,
        'ignite_embed_url_template' => $ignite_embed_url_template,
        // Statischer Fallback, falls die live vom Player abgefragte Dauer nicht verfügbar ist
        // (siehe loadSection() im Script unten) - ohne diesen Fallback blieb die
        // Fortschrittsleiste bei betroffenen Videos die ganze Wiedergabe über bei 0% stehen.
        'duration' => (int) ($section->duration ?? 0),
        'overlays' => array_values($overlays),
        'ebula_points' => array_values($ebula_points),
        'ebula_html' => $ebula_timetable ? $ebula_timetable->html : null,
        'stops' => array_values($formatted_stops),
    ];
}

$sesskey = sesskey();
?>

 <link rel="stylesheet" href="styles.css">

<div id="current-section-title">Abschnitt wird geladen...</div>
<div id="progress-text">Fortschritt wird geladen...</div>

<div class="player-container">
    <div class="video-section">
        <div id="video-container">
            <video id="railvideo" style="display: none;"></video>
            <iframe id="vimeo-player" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen style="display: none;"></iframe>

            <!-- Overlays -->
            <div id="overlay-container"></div>

            <!-- Eigene Fortschrittsleiste (ersetzt die nativen Player-Controls) mit
                 Stopp-Markierungen - einheitlich für Upload/Vimeo/ignite.video, da keiner der
                 drei nativen Scrubber eigene Markierungen unterstützt. Liegt als Kind von
                 #video-container, damit sie beim Vollbild (requestFullscreen auf genau dieses
                 Element) automatisch mit sichtbar bleibt. -->
            <div class="video-progress-bar-wrap">
                <button type="button" id="playpause-btn" aria-label="<?php echo get_string('playpause', 'mod_railviewer'); ?>">▶️</button>
                <span id="time-display">0:00 / 0:00</span>
                <div id="seek-track" class="seek-track">
                    <div id="seek-fill" class="seek-fill"></div>
                    <div id="seek-markers" class="seek-markers"></div>
                    <div id="seek-thumb" class="seek-thumb"></div>
                </div>
                <?php if ($show_mute_button): ?>
                <button type="button" id="mute-btn" aria-label="<?php echo get_string('mutetoggle', 'mod_railviewer'); ?>">🔊</button>
                <?php endif; ?>
                <?php if (count($available_speeds) > 1): ?>
                <select id="speed-select" aria-label="<?php echo get_string('playbackspeed', 'mod_railviewer'); ?>">
                    <?php foreach ($available_speeds as $speed): ?>
                    <option value="<?php echo $speed; ?>"<?php echo abs($speed - 1) < 0.001 ? ' selected' : ''; ?>><?php echo rtrim(rtrim(number_format($speed, 2, ',', ''), '0'), ','); ?>×</option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <!-- Fullscreen Overlays -->
            <div id="fullscreen-overlay-container" style="display: none;"></div>
            <!-- Fullscreen Ebula Overlay -->
            <div id="fullscreen-ebula-overlay">
                <h4>Fahrplan</h4>
                <div id="fullscreen-yellow-line"></div>
                <div id="fullscreen-ebula-content">
                    <p>Fahrplandaten werden geladen...</p>
                </div>
            </div>
        </div>
        
        <div class="controls">
            <button id="prev-section">⬅️ Vorheriger Abschnitt</button>
            <button id="next-section">Nächster Abschnitt ➡️</button>
            <button id="fullscreen-btn">🔲 Vollbild</button>
        </div>
    </div>
    
    <div class="ebula-section">
        <div class="ebula-container">
            <h4>Fahrplan</h4>
            <div id="yellow-line"></div>
            <div id="ebula-content">
                <p>Fahrplandaten werden geladen...</p>
            </div>
        </div>
    </div>
</div>

<?php if ($routeusesvimeo): ?>
<script src="https://player.vimeo.com/api/player.js"></script>
<?php endif; ?>
<?php if ($routeusesignite): ?>
<?php
// Self-gehostet statt live von cdn.embed.ly geladen (Drittanbieter-Kontakt allein durchs Laden
// des Skripts vermieden) - siehe railvieweradmin/docs/DATENSCHUTZ.md, Abschnitt 5 (#4), und
// railvieweradmin/thirdpartylibs/playerjs/player.js für Herkunft/Lizenz. Cache-Busting anhand
// des Datei-Zeitstempels, analog zum $assetversion()-Muster in map.php.
$playerjspath = $CFG->dirroot . '/local/railvieweradmin/thirdpartylibs/playerjs/player.js';
$playerjsurl = new moodle_url('/local/railvieweradmin/thirdpartylibs/playerjs/player.js',
    ['v' => is_file($playerjspath) ? filemtime($playerjspath) : 0]);
?>
<script src="<?php echo $playerjsurl->out(false); ?>"></script>
<?php endif; ?>
<script>
// Grundlegende Variablen
const sesskey = <?= json_encode($sesskey) ?>;
const assignmentid = <?= $assignmentid ?>;
const cmid = <?= $cmid ?>;
let playcountid = <?= $playcountid ?>;
// Bereits abgeschlossene Zuweisungen (Review-Modus, siehe $isreview) dürfen frei vor- und
// zurückgespult werden - da nichts mehr "erarbeitet" werden muss. Bei einer noch laufenden
// Zuweisung darf über die eigene Fortschrittsleiste NICHT über den bisher erreichten Punkt
// hinaus vorgespult werden (siehe seekTo()/clampSeekTarget()), sonst könnte der eigentliche
// Zweck - das tatsächliche Ansehen des Videos - umgangen werden. Zurückspulen zum Wiederholen
// bleibt in beiden Fällen uneingeschränkt möglich.
const isReviewMode = <?= json_encode($isreview) ?>;

// Nur bei einem generischen Player (keine fest an eine Route gebundene Aktivität) darf beim
// Abschluss direkt in die Gegenrichtung gewechselt werden - die Partner-Zuweisung ist über
// eine auf eine Route beschränkte Aktivität gar nicht autorisiert (siehe
// railviewer_get_authorized_assignment() in local_railvieweradmin/lib.php), ein Wechsel würde
// dort nur in einen Fehler laufen.
const isGenericPlayer = <?= json_encode(empty($railviewer->routeid)) ?>;

// Übersetzte Strings (inkl. Anrede-Variante Du/Sie) für die JS-seitige Anzeige.
const str = {
    allPlaythroughsCompleted: <?= json_encode(local_railvieweradmin_get_address_string('allplaythroughscompleted', 'mod_railviewer')) ?>,
    // Sprachstring enthält den Platzhalter "__ROUTE__" als reinen Textbaustein (kein
    // Moodle-{$a}), wird clientseitig per replace() mit dem tatsächlichen Streckennamen befüllt.
    returnTripReady: <?= json_encode(local_railvieweradmin_get_address_string('returntripready', 'mod_railviewer')) ?>,
    watchAgain: <?= json_encode(get_string('watch_again', 'mod_railviewer')) ?>,
    watchReturnTrip: <?= json_encode(get_string('watch_return_trip', 'mod_railviewer')) ?>,
    backToOverview: <?= json_encode(get_string('back_to_overview', 'mod_railviewer')) ?>,
    playcountCompletedTemplate: <?= json_encode(get_string('playcountcompleted', 'mod_railviewer', '__N__')) ?>,
    confirmLeaveSection: <?= json_encode(get_string('confirm_leave_section', 'mod_railviewer')) ?>,
    seekAheadBlocked: <?= json_encode(get_string('seekahead_blocked', 'mod_railviewer')) ?>,
    speedUnsupportedIgnite: <?= json_encode(get_string('speed_unsupported_ignite', 'mod_railviewer')) ?>,
};

// Nicht-blockierende Benachrichtigung anstelle von alert() - blockiert nicht den gesamten
// Browser-Tab und wirkt weniger wie ein Systemfehler. duration=0 lässt die Meldung stehen,
// bis sie manuell entfernt wird (z.B. bei Weiterleitung direkt danach).
function showToast(message, type = 'info', duration = 4000) {
    document.querySelectorAll('.rv-toast').forEach(el => el.remove());

    const toast = document.createElement('div');
    toast.className = 'rv-toast';
    toast.setAttribute('role', 'alert');
    toast.textContent = message;
    const bg = type === 'error' ? '#c0392b' : (type === 'success' ? '#28a745' : '#333');
    toast.style.cssText = `
        position: fixed;
        bottom: 24px;
        left: 50%;
        transform: translateX(-50%);
        max-width: 90%;
        padding: 14px 22px;
        border-radius: 8px;
        color: #fff;
        font-size: 15px;
        box-shadow: 0 4px 16px rgba(0,0,0,0.3);
        z-index: 2147483647;
        background: ${bg};
    `;
    document.body.appendChild(toast);

    if (duration > 0) {
        setTimeout(() => toast.remove(), duration);
    }
    return toast;
}

/**
 * Overlay-Panel beim Abschluss aller Durchläufe: der Nutzer entscheidet aktiv, statt
 * automatisch nach ein paar Sekunden weitergeleitet zu werden (showToast() kann das nicht -
 * reiner Text, keine Buttons). Bleibt stehen, bis eine der Optionen angeklickt wird.
 *
 * @param {string} message Überschrift/Haupttext
 * @param {Array<{label: string, href: string, primary?: boolean}>} choices
 */
function showCompletionChoice(message, choices) {
    const overlay = document.createElement('div');
    overlay.className = 'rv-completion-overlay';
    overlay.setAttribute('role', 'alertdialog');
    overlay.style.cssText = `
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.55);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2147483647;
        padding: 16px;
    `;

    const panel = document.createElement('div');
    panel.style.cssText = `
        background: #fff;
        border-radius: 10px;
        padding: 24px;
        max-width: 420px;
        width: 100%;
        text-align: center;
        box-shadow: 0 8px 30px rgba(0,0,0,0.3);
    `;

    const text = document.createElement('p');
    text.textContent = '🎉 ' + message;
    text.style.cssText = 'font-size: 16px; margin: 0 0 18px 0;';
    panel.appendChild(text);

    const buttonrow = document.createElement('div');
    buttonrow.style.cssText = 'display: flex; flex-direction: column; gap: 10px;';
    choices.forEach(function(choice) {
        const btn = document.createElement('a');
        btn.textContent = choice.label;
        btn.href = choice.href;
        btn.className = 'btn ' + (choice.primary ? 'btn-primary' : 'btn-secondary');
        buttonrow.appendChild(btn);
    });
    panel.appendChild(buttonrow);

    overlay.appendChild(panel);
    document.body.appendChild(overlay);
}

function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}
const videoData = <?= json_encode($video_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

let currentIndex = 0;
let currentPlayer = null;
let currentPlayerType = null;
let lastLoggedTime = -1;

// Übergangs-Erkennung für Stopps (siehe checkForVideoStop) - geschwindigkeitsrobust, ersetzt
// die frühere Zeitfenster-basierte Prüfung.
let lastCheckedStopTime = -1;

// DOM-Elemente
const videoElement = document.getElementById('railvideo');
let vimeoElement = document.getElementById('vimeo-player');
const overlayContainer = document.getElementById('overlay-container');
const fullscreenOverlayContainer = document.getElementById('fullscreen-overlay-container');
const sectionTitle = document.getElementById('current-section-title');
const prevBtn = document.getElementById('prev-section');
const nextBtn = document.getElementById('next-section');
const fullscreenBtn = document.getElementById('fullscreen-btn');
const progressText = document.getElementById('progress-text');
const yellowLine = document.getElementById('yellow-line');
const ebulaContent = document.getElementById('ebula-content');

// Eigene Fortschrittsleiste (ersetzt native Player-Controls, siehe HTML-Kommentar)
const playPauseBtn = document.getElementById('playpause-btn');
const timeDisplay = document.getElementById('time-display');
const seekTrack = document.getElementById('seek-track');
const seekFill = document.getElementById('seek-fill');
const seekThumb = document.getElementById('seek-thumb');
const seekMarkers = document.getElementById('seek-markers');
const muteBtn = document.getElementById('mute-btn');
const speedSelect = document.getElementById('speed-select'); // null, wenn nur 1× erlaubt (Admin-Setting aus)

let currentDuration = 0;
let isSeeking = false;
let isPlaying = false;
let isMuted = false;
let maxReachedTime = 0; // weitester bisher tatsächlich erreichter Zeitpunkt (Anti-Vorspul-Sperre)

// Neue DOM-Elemente für Fullscreen-Ebula hinzufügen (nach den bestehenden)
const fullscreenEbulaOverlay = document.getElementById('fullscreen-ebula-overlay');
const fullscreenYellowLine = document.getElementById('fullscreen-yellow-line');
const fullscreenEbulaContent = document.getElementById('fullscreen-ebula-content');


// Fullscreen-Zustand
let isInFullscreen = false;

// Logging
const logUrl = M.cfg.wwwroot + '/mod/railviewer/log.php';

function logProgress(position, event) {
    return fetch(logUrl, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            sesskey,
            assignmentid,
            cmid,
            sectionid: videoData[currentIndex].sectionid,
            position,
            playcountid, 
            event: event || 'progress',
        }),
    }).catch(error => {
        console.error('Logging error:', error);
    });
}

// Overlay-Erstellung
function createOverlayDiv(overlay, isFullscreen = false) {
    const div = document.createElement('div');
    const prefix = isFullscreen ? 'fullscreen-overlay-' : 'overlay-';
    let className = isFullscreen ? 'fullscreen-overlay-element' : 'overlay-element';
    
    div.id = prefix + overlay.id;
    div.textContent = overlay.name;
    
    div.style.position = 'absolute';
    div.style.left = overlay.posx + '%';
    div.style.top = overlay.posy + '%';
    div.style.fontSize = (overlay.fontsize || 12) + 'px';
    div.style.color = overlay.fontcolor || '#ffffff';
    div.style.backgroundColor = overlay.backgroundcolor || 'rgba(0, 0, 0, 0.5)';
    div.style.border = '2px solid ' + (overlay.bordercolor || '#ffffff');
    div.style.padding = '5px 10px';
    div.style.borderRadius = '4px';
    div.style.whiteSpace = 'nowrap';
    div.style.pointerEvents = 'auto';
    div.style.userSelect = 'none';
    div.style.display = 'none';
    
    // CSS-Variable für die Border-Color der Marker-Pfeile setzen
    div.style.setProperty('--bordercolor', overlay.bordercolor || '#ffffff');
    
    // Marker-Position aus der Datenbank (Feldname: markerposition)
    const markerPosition = overlay.markerposition || 'center';
    
    // Marker-CSS-Klassen und Transform basierend auf marker-position setzen
    switch (markerPosition) {
        case 'top-left':
            className += ' marker-top-left';
            div.style.transformOrigin = 'top left';
            div.style.transform = 'translate(0%, 0%)';
            break;
        case 'top-center':
            className += ' marker-top';
            div.style.transformOrigin = 'top center';
            div.style.transform = 'translate(-50%, 0%)';
            break;
        case 'top-right':
            className += ' marker-top-right';
            div.style.transformOrigin = 'top right';
            div.style.transform = 'translate(-100%, 0%)';
            break;
        case 'center-left':
            className += ' marker-left';
            div.style.transformOrigin = 'center left';
            div.style.transform = 'translate(0%, -50%)';
            break;
        case 'center':
        case 'center-center':
            className += ' marker-none';
            div.style.transformOrigin = 'center center';
            div.style.transform = 'translate(-50%, -50%)';
            break;
        case 'center-right':
            className += ' marker-right';
            div.style.transformOrigin = 'center right';
            div.style.transform = 'translate(-100%, -50%)';
            break;
        case 'bottom-left':
            className += ' marker-bottom-left';
            div.style.transformOrigin = 'bottom left';
            div.style.transform = 'translate(0%, -100%)';
            break;
        case 'bottom-center':
            className += ' marker-bottom';
            div.style.transformOrigin = 'bottom center';
            div.style.transform = 'translate(-50%, -100%)';
            break;
        case 'bottom-right':
            className += ' marker-bottom-right';
            div.style.transformOrigin = 'bottom right';
            div.style.transform = 'translate(-100%, -100%)';
            break;
        default:
            // Standard: center ohne Marker
            className += ' marker-none';
            div.style.transformOrigin = 'center center';
            div.style.transform = 'translate(-50%, -50%)';
            break;
    }
    
    // Klassen-String setzen
    div.className = className;
    
    if (isFullscreen) {
        div.style.zIndex = '2147483647';
        // Größere Schrift für Fullscreen
        const fullscreenFontSize = Math.max((overlay.fontsize || 12) * 1.5, 16);
        div.style.fontSize = fullscreenFontSize + 'px';
    } else {
        div.style.zIndex = '1000';
    }
    
    if (overlay.fixedboxwidth && overlay.fixedboxwidth > 0) {
        div.style.width = overlay.fixedboxwidth + '%';
        div.style.whiteSpace = 'normal';
    }
    
    return div;
}

// Ebula-Hilfsfunktionen
function findSurroundingPoints(t, arr) {
    if (!arr || arr.length === 0) return [null, null];
    
    let prev = null;
    let next = null;
    
    // Debug: Zeige alle verfügbaren Punkte
    console.log('Ebula Points:', arr.map(p => `${p.videotime}s -> ${p.line_top}px (static: ${p.is_static})`));
    
    for (let i = 0; i < arr.length; i++) {
        const point = arr[i];
        const pointTime = parseFloat(point.videotime);
        
        if (pointTime <= t) {
            prev = point;
        }
        if (pointTime > t && !next) {
            next = point;
            break;
        }
    }
    
    console.log('Found surrounding points for time', t.toFixed(2), ':', {
        prev: prev ? `${prev.videotime}s -> ${prev.line_top}px` : 'null',
        next: next ? `${next.videotime}s -> ${next.line_top}px` : 'null'
    });
    
    return [prev, next];
}

// Erweiterte updateYellowLine Funktion
function updateYellowLine(currentTime) {
    const section = videoData[currentIndex];
    if (!section.ebula_points || section.ebula_points.length === 0) {
        yellowLine.style.display = 'none';
        fullscreenYellowLine.style.display = 'none';
        return;
    }
    
    const [prev, next] = findSurroundingPoints(currentTime, section.ebula_points);
    
    let topPosition = '0px';
    
    if (!prev && !next) {
        // Keine Punkte vorhanden
        topPosition = '0px';
    } else if (!prev && next) {
        // Vor dem ersten Punkt
        topPosition = parseFloat(next.line_top) + 'px';
    } else if (prev && !next) {
        // Nach dem letzten Punkt
        topPosition = parseFloat(prev.line_top) + 'px';
    } else if (prev && next) {
        // Zwischen zwei Punkten
        // Prüfe ob der AKTUELLE Punkt statisch ist
        if (prev.is_static === true || prev.is_static === 1) {
            // Statischer Punkt - keine Interpolation, bleibt an dieser Position
            topPosition = parseFloat(prev.line_top) + 'px';
        } else {
            // Interpolation zwischen prev und next
            const timeDiff = parseFloat(next.videotime) - parseFloat(prev.videotime);
            const currentProgress = currentTime - parseFloat(prev.videotime);
            
            if (timeDiff > 0) {
                const factor = Math.max(0, Math.min(1, currentProgress / timeDiff));
                const prevTop = parseFloat(prev.line_top);
                const nextTop = parseFloat(next.line_top);
                const interpolatedTop = prevTop + factor * (nextTop - prevTop);
                topPosition = Math.round(interpolatedTop) + 'px';
                
                // Erweiterte Debug-Ausgabe für Interpolation
                console.log('Interpolation:', {
                    factor: factor.toFixed(3),
                    prevTop: prevTop,
                    nextTop: nextTop,
                    interpolatedTop: Math.round(interpolatedTop)
                });
            } else {
                // Zeitdifferenz ist 0, verwende prev Position
                topPosition = parseFloat(prev.line_top) + 'px';
            }
        }
    }
    
    // Debug-Ausgabe (können Sie später entfernen)
    console.log('Yellow Line Update:', {
        currentTime: currentTime.toFixed(2),
        prev: prev ? `${prev.videotime}s -> ${prev.line_top}px (static: ${prev.is_static})` : 'null',
        next: next ? `${next.videotime}s -> ${next.line_top}px` : 'null',
        topPosition: topPosition
    });
    
    const topPx = parseFloat(topPosition);

    // Normale gelbe Linie (nur wenn nicht im Fullscreen)
    if (!isInFullscreen) {
        yellowLine.style.display = 'block';
        yellowLine.style.top = topPosition;
        scrollToKeepLineVisible(document.querySelector('.ebula-container'), topPx);
    } else {
        yellowLine.style.display = 'none';
    }

    // Fullscreen gelbe Linie (nur im Fullscreen)
    if (isInFullscreen) {
        fullscreenYellowLine.style.display = 'block';
        fullscreenYellowLine.style.top = topPosition;
        scrollToKeepLineVisible(fullscreenEbulaOverlay, topPx);
    } else {
        fullscreenYellowLine.style.display = 'none';
    }
}

/**
 * Scrollt den EBuLa-Container (statt der gelben Linie selbst) nach, sobald die Linie den
 * sichtbaren Bereich verlassen würde - die Linie markiert eine feste Position innerhalb der
 * (potenziell langen) Fahrplantabelle, es ist also die Tabelle, die mitscrollen muss, nicht
 * die Linie. Scrollt nur, wenn die Linie tatsächlich (fast) aus dem sichtbaren Bereich läuft,
 * damit ein manuelles Scrollen des Nutzers nicht bei jedem Zeit-Update sofort überschrieben wird.
 *
 * @param {HTMLElement} container - das scrollende Element (.ebula-container bzw. der Fullscreen-Overlay)
 * @param {number} linetop - Position der gelben Linie in px relativ zum Container-Inhalt
 */
function scrollToKeepLineVisible(container, linetop) {
    if (!container) return;

    const margin = 40; // Sicherheitsabstand zum oberen/unteren Rand des sichtbaren Bereichs
    const visibleTop = container.scrollTop;
    const visibleBottom = visibleTop + container.clientHeight;

    if (linetop < visibleTop + margin || linetop > visibleBottom - margin) {
        container.scrollTo({
            top: Math.max(0, linetop - container.clientHeight / 2),
            behavior: 'smooth',
        });
    }
}
// ---------------------------------------------------------------------------
// Eigene Fortschrittsleiste: Zeitanzeige, Füllstand, Stopp-Markierungen, Klick-/Drag-Seek.
// Einheitlich für alle drei Videoquellen, da keiner der nativen Player-Scrubber (HTML5-
// <video>, Vimeo, ignite.video) eine API für eigene Markierungen bietet.
// ---------------------------------------------------------------------------

function formatTime(seconds) {
    if (!isFinite(seconds) || seconds < 0) seconds = 0;
    const m = Math.floor(seconds / 60);
    const s = Math.floor(seconds % 60);
    return m + ':' + String(s).padStart(2, '0');
}

function updatePlayPauseIcon() {
    playPauseBtn.textContent = isPlaying ? '⏸️' : '▶️';
}

function updateProgressBar(currentTime) {
    timeDisplay.textContent = formatTime(currentTime) + ' / ' + formatTime(currentDuration);
    if (isSeeking || !currentDuration) return;
    const pct = Math.max(0, Math.min(100, (currentTime / currentDuration) * 100));
    seekFill.style.width = pct + '%';
    seekThumb.style.left = pct + '%';
}

function resetProgressBar() {
    currentDuration = 0;
    maxReachedTime = 0;
    lastCheckedStopTime = -1;
    seekFill.style.width = '0%';
    seekThumb.style.left = '0%';
    seekMarkers.innerHTML = '';
    timeDisplay.textContent = '0:00 / 0:00';
    isPlaying = false;
    updatePlayPauseIcon();
}

/**
 * Begrenzt einen Seek-Zielzeitpunkt: nie negativ, nie über die Videodauer hinaus, und - außer
 * im Review-Modus (siehe isReviewMode) - nie über den bisher tatsächlich erreichten Zeitpunkt
 * hinaus. Rückwärts springen bleibt immer uneingeschränkt möglich.
 */
function clampSeekTarget(time) {
    time = Math.max(0, time);
    if (currentDuration) {
        time = Math.min(currentDuration, time);
    }
    if (!isReviewMode && time > maxReachedTime) {
        time = maxReachedTime;
    }
    return time;
}

/**
 * Zeichnet für jeden in railviewer_stops hinterlegten Stopp-Punkt eine kleine Markierung auf
 * der Fortschrittsleiste (Position = stoptime/duration), anklickbar zum direkten Anspringen.
 */
function renderStopMarkers(section, duration) {
    seekMarkers.innerHTML = '';
    if (!duration || !section.stops || !section.stops.length) return;

    section.stops.forEach(stop => {
        const stoptime = parseFloat(stop.stoptime);
        const pct = Math.max(0, Math.min(100, (stoptime / duration) * 100));
        const marker = document.createElement('div');
        marker.className = 'seek-marker';
        marker.style.left = pct + '%';
        marker.title = stop.name || '';
        marker.addEventListener('click', (e) => {
            e.stopPropagation();
            seekTo(stoptime);
        });
        seekMarkers.appendChild(marker);
    });
}

/** Springt zeitquellen-übergreifend zu einer bestimmten Videozeit (Vorspul-Sperre siehe clampSeekTarget()). */
function seekTo(requestedTime) {
    const time = clampSeekTarget(requestedTime);
    if (!isReviewMode && requestedTime > maxReachedTime + 0.5) {
        showToast(str.seekAheadBlocked, 'info', 2500);
    }
    // Beim Zurückspulen auch die Stopp-Übergangs-Erkennung zurücksetzen, damit ein Stopp
    // zwischen neuer und alter Position beim erneuten Durchspielen wieder auslöst.
    lastCheckedStopTime = Math.min(lastCheckedStopTime, time);
    if ((currentPlayerType === 'vimeo' || currentPlayerType === 'ignite') && currentPlayer) {
        currentPlayer.setCurrentTime(time);
    } else if (currentPlayerType === 'video') {
        videoElement.currentTime = time;
    }
    updateProgressBar(time);
}

function seekTrackClientXToTime(clientX) {
    const rect = seekTrack.getBoundingClientRect();
    const fraction = rect.width > 0 ? Math.max(0, Math.min(1, (clientX - rect.left) / rect.width)) : 0;
    return fraction * (currentDuration || 0);
}

function handleSeekMove(e) {
    if (!isSeeking) return;
    e.preventDefault();
    const clientX = e.touches ? e.touches[0].clientX : e.clientX;
    updateProgressBar(clampSeekTarget(seekTrackClientXToTime(clientX)));
}

function handleSeekEnd(e) {
    if (!isSeeking) return;
    const clientX = e.changedTouches ? e.changedTouches[0].clientX : e.clientX;
    isSeeking = false;
    seekTo(seekTrackClientXToTime(clientX));
    document.removeEventListener('mousemove', handleSeekMove);
    document.removeEventListener('mouseup', handleSeekEnd);
    document.removeEventListener('touchmove', handleSeekMove);
    document.removeEventListener('touchend', handleSeekEnd);
}

function handleSeekStart(e) {
    if (!currentDuration) return;
    isSeeking = true;
    handleSeekMove(e);
    document.addEventListener('mousemove', handleSeekMove);
    document.addEventListener('mouseup', handleSeekEnd);
    document.addEventListener('touchmove', handleSeekMove, { passive: false });
    document.addEventListener('touchend', handleSeekEnd);
}

seekTrack.addEventListener('mousedown', handleSeekStart);
seekTrack.addEventListener('touchstart', handleSeekStart, { passive: false });

playPauseBtn.addEventListener('click', () => {
    if (isPlaying) {
        if ((currentPlayerType === 'vimeo' || currentPlayerType === 'ignite') && currentPlayer) {
            currentPlayer.pause();
        } else {
            videoElement.pause();
        }
    } else {
        playVideo();
    }
});

// muteBtn ist null, wenn der Admin den Stumm-Regler in den Settings deaktiviert hat.
if (muteBtn) {
    muteBtn.addEventListener('click', () => {
        isMuted = !isMuted;
        if (currentPlayerType === 'vimeo' && currentPlayer) {
            currentPlayer.setVolume(isMuted ? 0 : 1);
        } else if (currentPlayerType === 'ignite' && currentPlayer) {
            isMuted ? currentPlayer.mute() : currentPlayer.unmute();
        } else {
            videoElement.muted = isMuted;
        }
        muteBtn.textContent = isMuted ? '🔇' : '🔊';
    });
}

/**
 * Setzt die Wiedergabegeschwindigkeit. Nur bei Upload/Vimeo unterstützt - die player.js-
 * Bibliothek von ignite.video kennt keine setPlaybackRate()-Methode (siehe Kommentar in
 * play.php/PHP-Teil); für diese Quelle wird der Regler in loadSection() deaktiviert, diese
 * Funktion also für 'ignite' gar nicht erst aufgerufen.
 */
function setPlaybackRate(rate) {
    if (currentPlayerType === 'vimeo' && currentPlayer) {
        currentPlayer.setPlaybackRate(rate).catch(error => {
            console.error('Vimeo setPlaybackRate fehlgeschlagen:', error);
        });
    } else if (currentPlayerType === 'video') {
        videoElement.playbackRate = rate;
    }
}

if (speedSelect) {
    speedSelect.addEventListener('change', () => {
        setPlaybackRate(parseFloat(speedSelect.value));
    });
}

function handleNativePlay() { isPlaying = true; updatePlayPauseIcon(); }
function handleNativePause() { isPlaying = false; updatePlayPauseIcon(); }

// Overlay-Updates
// Ab dieser Wiedergabezeit gilt der aktuelle Abschnitt als "begonnen" - ein Wechsel des
// Abschnitts wird dann vorher bestätigt, um versehentlichen Fortschrittsverlust zu vermeiden.
let sectionHasProgressed = false;

function updateOverlaysAtTime(currentTime) {
    const section = videoData[currentIndex];

    if (currentTime > 3) {
        sectionHasProgressed = true;
    }

    // Weitesten tatsächlich erreichten Zeitpunkt fortschreiben - Grundlage der Vorspul-Sperre
    // in clampSeekTarget()/seekTo(). Absichtlich nur beim normalen Fortschritt der Wiedergabe
    // erhöht, nie durch Seeken selbst (sonst könnte man sich per Seek "hocharbeiten").
    if (!isSeeking && currentTime > maxReachedTime) {
        maxReachedTime = currentTime;
    }

    section.overlays.forEach(overlay => {
        const normalDiv = document.getElementById('overlay-' + overlay.id);
        const fullscreenDiv = document.getElementById('fullscreen-overlay-' + overlay.id);

        const shouldShow = currentTime >= overlay.starttime && currentTime <= overlay.endtime;

        // Normale Overlays (nur wenn nicht im Fullscreen)
        if (normalDiv) {
            normalDiv.style.display = (!isInFullscreen && shouldShow) ? 'block' : 'none';
        }

        // Fullscreen-Overlays (nur im Fullscreen)
        if (fullscreenDiv) {
            fullscreenDiv.style.display = (isInFullscreen && shouldShow) ? 'block' : 'none';
        }
    });

    updateYellowLine(currentTime);
    updateProgressBar(currentTime);
}

// Video Event Handler
function videoTimeUpdateHandler() {
    if (currentPlayerType !== 'video') return;

    const currentTime = videoElement.currentTime;
    updateOverlaysAtTime(currentTime);
    checkForVideoStop(videoData[currentIndex], currentTime);

    const currentSecond = Math.floor(currentTime);
    if (currentSecond > 0 && currentSecond - lastLoggedTime >= 10) {
        lastLoggedTime = currentSecond;
        logProgress(currentSecond);
    }
}

async function handleVideoEnd() {
    try {
        let duration = 0;
        if (currentPlayerType === 'vimeo') {
            duration = await currentPlayer.getDuration();
        } else if (currentPlayerType === 'ignite') {
            // player.js liefert die Dauer per Callback statt als Promise.
            duration = await new Promise(resolve => currentPlayer.getDuration(resolve));
        } else {
            duration = videoElement.duration;
        }
        
        await logProgress(Math.floor(duration), 'ended');
        lastLoggedTime = -1;

        const isLastSection = currentIndex === videoData.length - 1;

        if (isLastSection) {
            // Marker schreiben
            await fetch(logUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    sesskey,
                    assignmentid,
                    cmid,
                    sectionid: -1,
                    position: 0,
                    playcountid,
                    event: 'playcount_complete'
                }),
            });

            // Prüfen ob alle Durchläufe abgeschlossen
            const response = await fetch(M.cfg.wwwroot + '/mod/railviewer/complete_assignment.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ assignmentid, cmid, sesskey })
            });

            const result = await response.json();

            if (result.is_complete) {
                const overviewUrl = M.cfg.wwwroot + '/mod/railviewer/view.php?id=' + cmid;
                const replayUrl = M.cfg.wwwroot + '/mod/railviewer/play.php?assignmentid=' + assignmentid + '&cmid=' + cmid;

                let message = str.allPlaythroughsCompleted;
                const choices = [
                    { label: str.watchAgain, href: replayUrl },
                ];

                // "Rückfahrt ansehen" nur anbieten, wenn es tatsächlich eine verknüpfte,
                // noch nicht abgeschlossene Gegenrichtungs-Zuweisung gibt UND diese Aktivität
                // ein generischer Player ist (siehe isGenericPlayer weiter oben).
                if (isGenericPlayer && result.partner && !result.partner.iscomplete) {
                    message = str.returnTripReady.replace('__ROUTE__', result.partner.routename);
                    const partnerUrl = M.cfg.wwwroot + '/mod/railviewer/play.php?assignmentid=' + result.partner.assignmentid + '&cmid=' + cmid;
                    choices.push({ label: str.watchReturnTrip, href: partnerUrl, primary: true });
                }

                choices.push({ label: str.backToOverview, href: overviewUrl, primary: choices.length === 1 });

                showCompletionChoice(message, choices);
                return;
            }

            playcountid += 1;
            currentIndex = 0;
            showToast(str.playcountCompletedTemplate.replace('__N__', playcountid), 'success');
            await delay(1500);
            await loadSection(currentIndex);
            setTimeout(() => playVideo(), 500);

        } else {
            currentIndex++;
            await loadSection(currentIndex);
            setTimeout(() => playVideo(), 500);
        }
        
    } catch (error) {
        console.error('Error in handleVideoEnd:', error);
        showToast('Fehler beim Verarbeiten des Video-Endes. Bitte laden Sie die Seite neu.', 'error');
    }
}

// Vimeo Player Setup
function setupVimeoPlayer(section, iframeElement) {
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
            reject(new Error('Vimeo player setup timeout'));
        }, 15000);
        
        try {
            currentPlayer = new Vimeo.Player(iframeElement);
            
            currentPlayer.ready().then(() => {
                clearTimeout(timeout);
                
                currentPlayer.on('timeupdate', function(data) {
                    const currentTime = data.seconds;
                    updateOverlaysAtTime(currentTime);
                    checkForVideoStop(section,currentTime);

                    const currentSecond = Math.floor(currentTime);
                    if (currentSecond > 0 && currentSecond - lastLoggedTime >= 10) {
                        lastLoggedTime = currentSecond;
                        logProgress(currentSecond);
                    }
                });
                
                currentPlayer.on('ended', () => {
                    handleVideoEnd();
                });

                currentPlayer.on('play', () => { isPlaying = true; updatePlayPauseIcon(); });
                currentPlayer.on('pause', () => { isPlaying = false; updatePlayPauseIcon(); });

                resolve();
            }).catch(error => {
                clearTimeout(timeout);
                reject(error);
            });
            
        } catch (error) {
            clearTimeout(timeout);
            reject(error);
        }
    });
}

// ignite.video Player Setup - nutzt die player.js-Bibliothek (Embedly), API-kompatibel mit
// Vimeo's eigenem Player-SDK, aber event- statt promise-basiert (siehe 'ready'-Event statt
// .ready(), Callback- statt Promise-Rückgabe bei getDuration() etc.).
function setupIgnitePlayer(section, iframeElement) {
    return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
            reject(new Error('ignite.video player setup timeout'));
        }, 15000);

        try {
            currentPlayer = new playerjs.Player(iframeElement);

            currentPlayer.on('ready', () => {
                clearTimeout(timeout);

                currentPlayer.on('timeupdate', function(data) {
                    const currentTime = data.seconds;
                    updateOverlaysAtTime(currentTime);
                    checkForVideoStop(section, currentTime);

                    const currentSecond = Math.floor(currentTime);
                    if (currentSecond > 0 && currentSecond - lastLoggedTime >= 10) {
                        lastLoggedTime = currentSecond;
                        logProgress(currentSecond);
                    }
                });

                currentPlayer.on('ended', () => {
                    handleVideoEnd();
                });

                currentPlayer.on('play', () => { isPlaying = true; updatePlayPauseIcon(); });
                currentPlayer.on('pause', () => { isPlaying = false; updatePlayPauseIcon(); });

                resolve();
            });

            currentPlayer.on('error', (error) => {
                clearTimeout(timeout);
                reject(error instanceof Error ? error : new Error('ignite.video player error'));
            });

        } catch (error) {
            clearTimeout(timeout);
            reject(error);
        }
    });
}

// Section Loading
async function loadSection(index) {
    const section = videoData[index];
    sectionTitle.textContent = `Abschnitt ${index + 1} von ${videoData.length}: ${section.name}`;
    progressText.textContent = `Du schaust diesen Abschnitt im Durchlauf #${playcountid}.`;

    lastLoggedTime = -1;
    sectionHasProgressed = false;
    resetProgressBar();

    // Cleanup vorheriger Player
    if (currentPlayer) {
        try {
            if (currentPlayerType === 'vimeo') {
                await currentPlayer.pause();
                currentPlayer.destroy();
            } else if (currentPlayerType === 'ignite') {
                currentPlayer.pause();
                if (typeof currentPlayer.off === 'function') {
                    currentPlayer.off('timeupdate');
                    currentPlayer.off('ended');
                    currentPlayer.off('play');
                    currentPlayer.off('pause');
                }
            } else if (currentPlayerType === 'video') {
                videoElement.pause();
                videoElement.removeEventListener('timeupdate', videoTimeUpdateHandler);
                videoElement.removeEventListener('ended', handleVideoEnd);
                videoElement.removeEventListener('play', handleNativePlay);
                videoElement.removeEventListener('pause', handleNativePause);
            }
        } catch (e) {
            console.log('Cleanup error (expected):', e.message);
        }
        currentPlayer = null;
        currentPlayerType = null;
    }

    // Player zurücksetzen
    videoElement.style.display = 'none';
    videoElement.src = '';
    videoElement.load();
    
    const oldIframe = document.getElementById('vimeo-player');
    if (oldIframe) {
        oldIframe.style.display = 'none';
    }
    
    if (section.video_source === 'vimeo' && section.vimeo_id) {
        currentPlayerType = 'vimeo';
        
        // Neue Vimeo iframe erstellen
        const videoContainer = document.getElementById('video-container');
        if (oldIframe) {
            oldIframe.remove();
        }
        
        const newIframe = document.createElement('iframe');
        newIframe.id = 'vimeo-player';
        newIframe.style.width = '100%';
        newIframe.style.height = '100%';
        newIframe.style.display = 'block';
        newIframe.frameBorder = '0';
        newIframe.allow = 'autoplay; fullscreen; picture-in-picture';
        newIframe.allowFullscreen = true;
        // controls=0: eigene Fortschrittsleiste ersetzt die native Vimeo-Leiste.
        newIframe.src = `https://player.vimeo.com/video/${section.vimeo_id}?api=1&player_id=vimeo-player&autoplay=0&muted=0&controls=0`;
        
        videoContainer.appendChild(newIframe);
        vimeoElement = newIframe;
        
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        try {
            await setupVimeoPlayer(section, newIframe);
        } catch (error) {
            console.error('Vimeo setup error:', error);
            showToast('Fehler beim Laden des Videos. Bitte laden Sie die Seite neu.', 'error');
            return;
        }
        
    } else if (section.video_source === 'ignite' && section.ignite_id) {
        currentPlayerType = 'ignite';

        // ignite.video-Player teilt sich dieselbe Iframe-Element-ID mit dem Vimeo-Player
        // (rein interner DOM-Bezeichner, wird von CSS/Cleanup-Code generisch behandelt).
        const videoContainer = document.getElementById('video-container');
        if (oldIframe) {
            oldIframe.remove();
        }

        const newIframe = document.createElement('iframe');
        newIframe.id = 'vimeo-player';
        newIframe.style.width = '100%';
        newIframe.style.height = '100%';
        newIframe.style.display = 'block';
        newIframe.frameBorder = '0';
        newIframe.allow = 'autoplay; fullscreen; picture-in-picture';
        newIframe.allowFullscreen = true;
        // URL-Muster aus den Einstellungen, {id} durch die tatsächliche Video-ID ersetzt
        // (Standard enthält bereits controls=false: eigene Fortschrittsleiste ersetzt die
        // native ignite.video-Leiste).
        newIframe.src = section.ignite_embed_url_template.replace('{id}', encodeURIComponent(section.ignite_id));

        videoContainer.appendChild(newIframe);
        vimeoElement = newIframe;

        try {
            await setupIgnitePlayer(section, newIframe);
        } catch (error) {
            console.error('ignite.video setup error:', error);
            showToast('Fehler beim Laden des Videos. Bitte laden Sie die Seite neu.', 'error');
            return;
        }

    } else if (section.videourl) {
        currentPlayerType = 'video';
        videoElement.style.display = 'block';
        videoElement.src = section.videourl;
        
        videoElement.addEventListener('timeupdate', videoTimeUpdateHandler);
        videoElement.addEventListener('ended', handleVideoEnd);
        videoElement.addEventListener('play', handleNativePlay);
        videoElement.addEventListener('pause', handleNativePause);

        await new Promise((resolve, reject) => {
            const handleLoad = () => {
                videoElement.removeEventListener('loadeddata', handleLoad);
                videoElement.removeEventListener('error', handleError);
                resolve();
            };
            
            const handleError = (e) => {
                videoElement.removeEventListener('loadeddata', handleLoad);
                videoElement.removeEventListener('error', handleError);
                reject(e);
            };
            
            videoElement.addEventListener('loadeddata', handleLoad);
            videoElement.addEventListener('error', handleError);
            videoElement.load();
        });
        
        currentPlayer = videoElement;
        
    } else {
        showToast('Kein Video für Abschnitt "' + section.name + '" gefunden.', 'error');
        updateButtons();
        return;
    }

    // Video-Dauer ermitteln (quellenabhängig sync/async) und darauf basierend die
    // Stopp-Markierungen auf der Fortschrittsleiste platzieren. updateProgressBar() bricht
    // komplett ab, solange currentDuration falsy ist (siehe dortiger Kommentar) - ohne
    // brauchbare Dauer bliebe die Fortschrittsleiste die ganze Wiedergabe über bei 0% stehen,
    // auch wenn das Video selbst normal weiterläuft.
    try {
        if (currentPlayerType === 'vimeo') {
            currentDuration = await currentPlayer.getDuration();
        } else if (currentPlayerType === 'ignite') {
            // player.js' getDuration()-Callback liefert bei manchen Embeds direkt nach 'ready'
            // noch 0/undefined (Metadaten des iframes sind offenbar nicht sofort verfügbar,
            // vermutlich genau die Ursache dafür, dass die Fortschrittsleiste bei ignite.video-
            // Videos nicht weiterlief). Deshalb: mit Timeout absichern (der Callback ist nicht
            // spezifiziert garantiert zu feuern) und bei einem ersten leeren Ergebnis einmal
            // kurz verzögert erneut versuchen, bevor auf den serverseitig hinterlegten Wert
            // zurückgefallen wird.
            const getIgniteDuration = () => Promise.race([
                new Promise(resolve => currentPlayer.getDuration(resolve)),
                new Promise(resolve => setTimeout(() => resolve(0), 3000)),
            ]);
            currentDuration = await getIgniteDuration();
            if (!currentDuration) {
                await new Promise(resolve => setTimeout(resolve, 500));
                currentDuration = await getIgniteDuration();
            }
        } else if (currentPlayerType === 'video') {
            currentDuration = videoElement.duration;
        }
    } catch (error) {
        console.error('Could not determine video duration:', error);
        currentDuration = 0;
    }

    // Letzter Ausweg, egal welche Quelle: der im Abschnitt hinterlegte statische Wert (siehe
    // edit_section.php) statt einer für die ganze Wiedergabe eingefrorenen Fortschrittsleiste.
    if (!currentDuration && section.duration) {
        console.warn('Live-Videodauer nicht ermittelbar, nutze hinterlegte Dauer aus dem Abschnitt.');
        currentDuration = section.duration;
    }

    renderStopMarkers(section, currentDuration);
    updateProgressBar(0);

    // Geschwindigkeitsregler: bei jedem neuen Abschnitt auf 1× zurücksetzen (kein
    // versehentliches Weiterlaufen einer hohen Geschwindigkeit in einen neuen Abschnitt) und
    // für ignite.video deaktivieren, da dort technisch nicht unterstützt (siehe setPlaybackRate()).
    if (speedSelect) {
        if (currentPlayerType === 'ignite') {
            speedSelect.disabled = true;
            speedSelect.title = str.speedUnsupportedIgnite;
        } else {
            speedSelect.disabled = false;
            speedSelect.title = '';
            speedSelect.value = '1';
            setPlaybackRate(1);
        }
    }

    // Overlays erstellen
    overlayContainer.innerHTML = '';
    fullscreenOverlayContainer.innerHTML = '';
    
    section.overlays.forEach(overlay => {
        // Normale Overlays
        const normalDiv = createOverlayDiv(overlay, false);
        overlayContainer.appendChild(normalDiv);
        
        // Fullscreen Overlays
        const fullscreenDiv = createOverlayDiv(overlay, true);
        fullscreenOverlayContainer.appendChild(fullscreenDiv);
    });
    
     // Ebula-Tabelle für beide Modi laden
    const ebulaHtml = section.ebula_html ? 
        '<table><thead><tr><th>km</th><th>Geschwindigkeit</th><th>Betriebsstelle</th></tr></thead><tbody>' + section.ebula_html + '</tbody></table>' :
        '<p>Keine Fahrplandaten für diesen Abschnitt verfügbar.</p>';
    
    // Normale Ebula-Tabelle
    ebulaContent.innerHTML = ebulaHtml;
    
    // Fullscreen Ebula-Tabelle
    fullscreenEbulaContent.innerHTML = ebulaHtml;

    updateButtons();
    
    await logProgress(0, 'section_start');
}

function playVideo() {
    if ((currentPlayerType === 'vimeo' || currentPlayerType === 'ignite') && currentPlayer) {
        currentPlayer.play();
    } else {
        videoElement.play();
    }
}
function showStopNotification(stop) {
    // Entferne vorherige Benachrichtigungen
    const existingNotifications = document.querySelectorAll('.stop-notification');
    existingNotifications.forEach(n => n.remove());
    
    const notification = document.createElement('div');
    notification.className = 'stop-notification';
    
    // Basis-Styling
    const baseStyle = `
        background: rgba(40, 167, 69, 0.95);
        color: white;
        padding: 20px 30px;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        text-align: center;
        font-size: 16px;
        font-weight: bold;
    `;
    
    // Unterschiedliche Positionierung je nach Fullscreen-Status
    if (isInFullscreen) {
        // Im Fullscreen: absolute Positionierung innerhalb des Fullscreen-Containers
        notification.style.cssText = baseStyle + `
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 2147483647;
        `;
        
        // Notification zum Fullscreen-Container hinzufügen
        const videoContainer = document.getElementById('video-container');
        videoContainer.appendChild(notification);
    } else {
        // Normal: fixed Positionierung
        notification.style.cssText = baseStyle + `
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 2147483647;
        `;
        
        // Notification zum Body hinzufügen
        document.body.appendChild(notification);
    }
    
    notification.innerHTML = `
        <div>Video angehalten bei:</div>
        <div style="margin-top: 8px; font-size: 18px;">"${stop.name}"</div>
        <div style="margin-top: 12px; font-size: 14px; font-weight: normal;">
            <button onclick="resumeVideoWithTracking(); this.parentNode.parentNode.remove();" 
                    style="background: white; color: #28a745; border: none; padding: 8px 16px; border-radius: 5px; cursor: pointer;">
                Weiter abspielen
            </button>
        </div>
    `;
}
// Resume-Funktion nach einem Stopp - kein manuelles Tracking mehr nötig, siehe
// checkForVideoStop(): lastCheckedStopTime steht nach dem Pausieren bereits exakt auf der
// Stop-Zeit, ein erneutes Auslösen desselben Stopps ist dadurch beim Fortsetzen ausgeschlossen.
function resumeVideoWithTracking() {
    if ((currentPlayerType === 'vimeo' || currentPlayerType === 'ignite') && currentPlayer) {
        currentPlayer.play();
    } else {
        videoElement.play();
    }
}

function pauseForStop(stop) {
    if (currentPlayerType === 'vimeo' && currentPlayer) {
        currentPlayer.pause().then(function() {
            showStopNotification(stop);
        }).catch(function(error) {
            console.error('Fehler beim Pausieren von Vimeo:', error);
        });
    } else if (currentPlayerType === 'ignite' && currentPlayer) {
        // player.js pause() liefert kein Promise - Notification direkt anzeigen.
        currentPlayer.pause();
        showStopNotification(stop);
    } else {
        videoElement.pause();
        showStopNotification(stop);
    }
}

// Prüft, ob seit dem letzten Aufruf eine Stopp-Zeit (railviewer_stops) ÜBERQUERT wurde, und
// pausiert in diesem Fall die Wiedergabe. Übergangs-Erkennung (lastCheckedStopTime < stopTime
// <= currentTime) statt eines festen Zeitfensters: bei hoher Wiedergabegeschwindigkeit (siehe
// Geschwindigkeitsregler) können zwischen zwei timeupdate-Aufrufen mehrere Sekunden Videozeit
// vergehen - ein festes [stopTime, stopTime+1]-Fenster könnte dann komplett übersprungen werden
// und der Stopp würde nie ausgelöst.
function checkForVideoStop(section, currentTime) {
    if (section.stops && section.stops.length) {
        // section.stops ist serverseitig nach stoptime ASC sortiert (siehe play.php) - bei
        // mehreren in einer Lücke überquerten Stopps wird nur der früheste ausgelöst, die
        // übrigen folgen nach dem Fortsetzen beim nächsten Überqueren.
        for (const stop of section.stops) {
            const stopTime = parseFloat(stop.stoptime);
            if (stopTime > lastCheckedStopTime && stopTime <= currentTime) {
                lastCheckedStopTime = stopTime;
                pauseForStop(stop);
                return;
            }
        }
    }
    lastCheckedStopTime = currentTime;
}
// Fullscreen-Funktionen
function toggleFullscreen() {
    const videoContainer = document.getElementById('video-container');
    
    if (!isInFullscreen) {
        // Fullscreen einschalten
        if (videoContainer.requestFullscreen) {
            videoContainer.requestFullscreen();
        } else if (videoContainer.webkitRequestFullscreen) {
            videoContainer.webkitRequestFullscreen();
        } else if (videoContainer.mozRequestFullScreen) {
            videoContainer.mozRequestFullScreen();
        } else if (videoContainer.msRequestFullscreen) {
            videoContainer.msRequestFullscreen();
        }
    } else {
        // Fullscreen ausschalten
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.mozCancelFullScreen) {
            document.mozCancelFullScreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }
    }
}

// Erweiterte handleFullscreenChange Funktion
function handleFullscreenChange() {
    const fullscreenElement = document.fullscreenElement || 
                           document.webkitFullscreenElement || 
                           document.mozFullScreenElement || 
                           document.msFullscreenElement;
    
    isInFullscreen = !!fullscreenElement;
    
    if (isInFullscreen) {
        // Fullscreen-Overlays anzeigen, normale verstecken
        overlayContainer.style.display = 'none';
        fullscreenOverlayContainer.style.display = 'block';
        fullscreenEbulaOverlay.style.display = 'block';
        
        // Fullscreen-Container zum Fullscreen-Element hinzufügen
        if (fullscreenElement && !fullscreenElement.contains(fullscreenOverlayContainer)) {
            fullscreenElement.appendChild(fullscreenOverlayContainer);
        }
        if (fullscreenElement && !fullscreenElement.contains(fullscreenEbulaOverlay)) {
            fullscreenElement.appendChild(fullscreenEbulaOverlay);
        }
        
        fullscreenBtn.textContent = '🔳 Vollbild beenden';
    } else {
        // Normale Overlays anzeigen, Fullscreen verstecken
        overlayContainer.style.display = 'block';
        fullscreenOverlayContainer.style.display = 'none';
        fullscreenEbulaOverlay.style.display = 'none';
        
        // Container zurück zum Video-Container
        const videoContainer = document.getElementById('video-container');
        if (!videoContainer.contains(fullscreenOverlayContainer)) {
            videoContainer.appendChild(fullscreenOverlayContainer);
        }
        if (!videoContainer.contains(fullscreenEbulaOverlay)) {
            videoContainer.appendChild(fullscreenEbulaOverlay);
        }
        
        fullscreenBtn.textContent = '🔲 Vollbild';
    }
    
    // Overlays und Ebula nach kurzer Verzögerung aktualisieren
    setTimeout(() => {
        if (currentPlayerType === 'vimeo' && currentPlayer) {
            currentPlayer.getCurrentTime().then(time => {
                updateOverlaysAtTime(time);
            }).catch(e => console.log('Could not get Vimeo time:', e));
        } else if (currentPlayerType === 'ignite' && currentPlayer) {
            // player.js liefert die aktuelle Zeit per Callback statt als Promise.
            currentPlayer.getCurrentTime(time => updateOverlaysAtTime(time));
        } else if (currentPlayerType === 'video') {
            updateOverlaysAtTime(videoElement.currentTime);
        }
    }, 100);
}

// Fullscreen Event Listeners
document.addEventListener('fullscreenchange', handleFullscreenChange);
document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
document.addEventListener('mozfullscreenchange', handleFullscreenChange);
document.addEventListener('MSFullscreenChange', handleFullscreenChange);

function updateButtons() {
    prevBtn.disabled = currentIndex === 0;
    nextBtn.disabled = currentIndex === videoData.length - 1;
}

// Event Listeners
prevBtn.addEventListener('click', () => {
    if (currentIndex > 0 && confirmSectionChange()) {
        currentIndex--;
        loadSection(currentIndex).then(() => {
            setTimeout(() => playVideo(), 500);
        });
    }
});

nextBtn.addEventListener('click', () => {
    if (currentIndex < videoData.length - 1 && confirmSectionChange()) {
        currentIndex++;
        loadSection(currentIndex).then(() => {
            setTimeout(() => playVideo(), 500);
        });
    }
});

// Nur nachfragen, wenn im aktuellen Abschnitt bereits nennenswert Fortschritt gemacht wurde -
// bei einem Wechsel direkt nach dem Laden (noch nichts gesehen) ist keine Rückfrage nötig.
function confirmSectionChange() {
    if (!sectionHasProgressed) {
        return true;
    }
    return confirm(str.confirmLeaveSection);
}

fullscreenBtn.addEventListener('click', () => {
    toggleFullscreen();
});

// Start
loadSection(currentIndex);
</script>

<?php
echo $OUTPUT->footer();
?>
