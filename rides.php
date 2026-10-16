<?php
// Lernfahrten erfassen, Fortschritt je Abschnitt/Methode ansehen, Ersetzungsregeln anwenden.
require_once('../../config.php');
require_once($CFG->dirroot . '/local/railvieweradmin/lib.php');
require_login();

$context = context_system::instance();
$self = new moodle_url('/mod/railviewer/rides.php');
$PAGE->set_context($context);
$PAGE->set_url($self);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('my_rides', 'local_railvieweradmin'));
$PAGE->set_heading(get_string('my_rides', 'local_railvieweradmin'));
$PAGE->requires->css(new moodle_url('/mod/railviewer/styles_rides.css', ['v' => filemtime(__DIR__ . '/styles_rides.css')]));

global $DB, $USER;
$learning = '\local_railvieweradmin\learning';

if (!$learning::enabled()) {
    throw new moodle_exception('learning_feature_disabled', 'local_railvieweradmin');
}

$error = '';

// Nutzerentscheidung: Ersetzungsregel für eine Zuweisung anwenden / nicht anwenden.
if (optional_param('action', '', PARAM_ALPHA) === 'subst') {
    require_sesskey();
    $assignment = $DB->get_record('railviewer_assignments', ['id' => required_param('assignmentid', PARAM_INT), 'userid' => $USER->id], '*', MUST_EXIST);
    $rule = $DB->get_record('railviewer_route_substs', ['id' => required_param('substid', PARAM_INT),
        'routeid' => $assignment->routeid, 'userchoice' => 1], '*', MUST_EXIST);
    if (empty($assignment->completedtime)) {
        $applied = optional_param('applied', 0, PARAM_BOOL) ? 1 : 0;
        $existing = $DB->get_record('railviewer_assignment_subst', ['assignmentid' => $assignment->id, 'substid' => $rule->id]);
        if ($existing) {
            $existing->applied = $applied;
            $existing->timemodified = time();
            $DB->update_record('railviewer_assignment_subst', $existing);
        } else {
            $DB->insert_record('railviewer_assignment_subst', (object) ['assignmentid' => $assignment->id,
                'substid' => $rule->id, 'applied' => $applied, 'timemodified' => time()]);
        }
        local_railvieweradmin_sync_assignment_progress($assignment->id);
    }
    redirect($self);
}

// Abschlusserklärung bestätigen oder ablehnen.
if (optional_param('action', '', PARAM_ALPHA) === 'declaration') {
    require_sesskey();
    $accept = optional_param('decision', '', PARAM_ALPHA) === 'accept';
    try {
        $learning::answer_declaration((int) $USER->id, $accept, optional_param('reason', '', PARAM_TEXT));
        redirect($self, get_string($accept ? 'declaration_accepted_msg' : 'declaration_declined_msg', 'local_railvieweradmin'),
            null, $accept ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

// Neue Fahrt.
if (optional_param('action', '', PARAM_ALPHA) === 'addride') {
    require_sesskey();
    try {
        $assignmentid = required_param('assignmentid', PARAM_INT);
        $assignment = $DB->get_record('railviewer_assignments', ['id' => $assignmentid, 'userid' => $USER->id], '*', MUST_EXIST);
        $sections = array_values($learning::route_sections((int) $assignment->routeid));
        if (!$sections) {
            throw new moodle_exception('ride_invalid_range', 'local_railvieweradmin');
        }
        if (optional_param('scope', 'full', PARAM_ALPHA) === 'full') {
            $from = (int) $sections[0]->id;
            $to = (int) $sections[count($sections) - 1]->id;
        } else {
            $from = required_param('fromsectionid', PARAM_INT);
            $to = required_param('tosectionid', PARAM_INT);
        }
        $date = optional_param('ridedate', '', PARAM_RAW);
        $timestamp = $date !== '' ? strtotime($date . ' 12:00:00') : 0;
        $external = optional_param('external', 0, PARAM_BOOL);
        \local_railvieweradmin\rides::create($USER, [
            'assignmentid' => $assignmentid,
            'methodid' => required_param('methodid', PARAM_INT),
            'ridedate' => (int) $timestamp,
            'trainnumber' => required_param('trainnumber', PARAM_TEXT),
            'fromsectionid' => $from, 'tosectionid' => $to,
            'trainerid' => optional_param('trainerid', 0, PARAM_INT),
            'external' => $external,
            'evu' => optional_param('evu', '', PARAM_TEXT),
            'trainername' => optional_param('trainername', '', PARAM_TEXT),
            'signature' => optional_param('signature', '', PARAM_RAW),
        ]);
        redirect(new moodle_url($self, ['assignmentid' => $assignmentid]), get_string($external ? 'ride_saved_external' : 'ride_saved_pending', 'local_railvieweradmin'),
            null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo html_writer::start_div('rv-rides');
if ($error !== '') {
    echo $OUTPUT->notification($error, 'error');
}

$overview = $learning::user_overview((int) $USER->id);
$methodoptions = [];
foreach ($learning::methods() as $m) {
    $methodoptions[$m->id] = format_string($m->name);
}
$total = count($overview['items']);
$donecount = 0;
foreach ($overview['items'] as $it) {
    $donecount += $it['status']['complete'] ? 1 : 0;
}
$pendingown = $DB->count_records('railviewer_rides', ['userid' => $USER->id, 'status' => 'pending']);
$declstatus = $learning::declaration_status((int) $USER->id, $overview['allcomplete']);

// ---- Kopf und Kennzahlen
echo html_writer::tag('h2', get_string('my_rides', 'local_railvieweradmin'));
echo html_writer::tag('p', get_string('rides_lead', 'local_railvieweradmin'), ['class' => 'rv-lead']);

$percent = $overview['soll'] > 0 ? min(100, (int) round(100 * $overview['ist'] / $overview['soll'])) : ($total && $donecount === $total ? 100 : 0);
echo html_writer::start_div('rv-stats');
echo html_writer::div(
    html_writer::div(get_string('stat_total_duration', 'local_railvieweradmin'), 'rv-stat-label') .
    html_writer::div($learning::format_minutes($overview['ist']) . ' / ' . $learning::format_minutes($overview['soll']), 'rv-stat-value') .
    html_writer::div('<span style="width:' . $percent . '%"></span>', 'rv-bar' . ($percent >= 100 ? ' is-done' : '')) .
    html_writer::div($percent . ' %' . ($overview['incomplete'] ? ' · ' . get_string('duration_incomplete_short', 'local_railvieweradmin') : ''), 'rv-stat-sub'),
    'rv-stat');
echo html_writer::div(
    html_writer::div(get_string('stat_routes_done', 'local_railvieweradmin'), 'rv-stat-label') .
    html_writer::div($donecount . ' / ' . $total, 'rv-stat-value') .
    html_writer::div($overview['einsatzstellen'] ? s(implode(', ', $overview['einsatzstellen'])) : '&nbsp;', 'rv-stat-sub'),
    'rv-stat');
echo html_writer::div(
    html_writer::div(get_string('stat_pending_confirm', 'local_railvieweradmin'), 'rv-stat-label') .
    html_writer::div((string) $pendingown, 'rv-stat-value') .
    html_writer::div(get_string('stat_pending_confirm_sub', 'local_railvieweradmin'), 'rv-stat-sub'),
    'rv-stat');
echo html_writer::end_div();

// ---- Hinweise: Bestätigungen als Ausbilder, Abschlusserklärung
echo \local_railvieweradmin\ui::confirmation_callout((int) $USER->id);

if ($declstatus === 'pending' || $declstatus === 'declined') {
    echo html_writer::start_div('rv-card is-warn');
    echo html_writer::tag('h3', get_string('declaration_title', 'local_railvieweradmin'));
    if ($declstatus === 'declined') {
        echo html_writer::div(get_string('declaration_declined_hint', 'local_railvieweradmin'), 'rv-callout');
    }
    echo html_writer::div($learning::render_declaration((int) $USER->id), 'railviewer-declaration');
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $self->out(false), 'class' => 'rv-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'declaration']);
    echo html_writer::div(html_writer::label(get_string('declaration_decline_reason', 'local_railvieweradmin'), 'decl-reason') .
        html_writer::empty_tag('input', ['type' => 'text', 'name' => 'reason', 'id' => 'decl-reason', 'class' => 'form-control']), 'rv-field');
    echo html_writer::tag('button', get_string('declaration_accept_btn', 'local_railvieweradmin'),
        ['type' => 'submit', 'name' => 'decision', 'value' => 'accept', 'class' => 'btn btn-success']) . ' ';
    echo html_writer::tag('button', get_string('declaration_decline_btn', 'local_railvieweradmin'),
        ['type' => 'submit', 'name' => 'decision', 'value' => 'decline', 'class' => 'btn btn-outline-danger']);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
} else if ($total && $learning::order_complete((int) $USER->id, $overview)) {
    echo $OUTPUT->notification(get_string('order_complete', 'local_railvieweradmin'), 'success');
}

// ---- Galerie der Strecken | Abschnitts-Raster der gewählten Strecke
$selectedid = optional_param('assignmentid', 0, PARAM_INT);
$selected = null;
foreach ($overview['items'] as $it) {
    if ((int) $it['assignment']->id === $selectedid) {
        $selected = $it;
    }
}
if (!$selected) {
    // Standard: erste nicht erledigte, nicht erlassene Strecke, sonst die erste.
    foreach ($overview['items'] as $it) {
        if (!$it['status']['complete'] && empty($it['assignment']->waived)) {
            $selected = $it;
            break;
        }
    }
    $selected = $selected ?: (reset($overview['items']) ?: null);
}

echo html_writer::start_div('rv-layout');

// Linke Spalte: Galerie. Jede Karte zeigt Status und einen Streifen mit einem Block je Abschnitt.
echo html_writer::start_div('rv-gallery');
echo html_writer::tag('h3', get_string('my_routes', 'local_railvieweradmin'), ['class' => 'rv-section-title', 'style' => 'margin-top:0']);
if (!$overview['items']) {
    echo $OUTPUT->notification(get_string('noroutesassigned', 'mod_railviewer'), 'info');
}
foreach ($overview['items'] as $item) {
    $a = $item['assignment'];
    $st = $item['status'];
    $dur = $item['duration'];
    $pct = $dur['soll'] > 0 ? min(100, (int) round(100 * $dur['ist'] / $dur['soll'])) : ($st['complete'] ? 100 : 0);
    $badge = !empty($a->waived) ? ['waived_label', 'badge-secondary']
        : ($st['complete'] ? ['status_done', 'badge-success'] : ['status_open', 'badge-warning']);
    $blocks = '';
    $okblocks = 0;
    foreach ($st['sections'] as $sid => $sec) {
        $secok = !empty($a->waived) || !array_filter($sec['cells'], function($c) { return !$c['complete']; });
        $okblocks += $secok ? 1 : 0;
        $blocks .= html_writer::span('', 'rv-seg' . ($secok ? ' is-ok' : ''), ['title' => $sec['name']]);
    }
    $isselected = $selected && (int) $selected['assignment']->id === (int) $a->id;
    $url = new moodle_url($self, ['assignmentid' => $a->id]);
    echo html_writer::link($url,
        html_writer::div(html_writer::span($learning::route_label($a), 'rv-gal-name') .
            html_writer::span(get_string($badge[0], 'local_railvieweradmin'), 'badge ' . $badge[1]), 'rv-gal-top') .
        html_writer::div($blocks, 'rv-segs') .
        html_writer::div(get_string('gallery_sections_done', 'local_railvieweradmin', (object) ['done' => $okblocks, 'total' => count($st['sections'])])
            . ' · ' . $pct . ' %', 'rv-gal-sub'),
        ['class' => 'rv-gal-card' . ($isselected ? ' is-selected' : '') . ($st['complete'] ? ' is-done' : '')]);
}
echo html_writer::end_div(); // rv-gallery

// Rechte Spalte: Raster der gewählten Strecke.
echo html_writer::start_div('rv-main');
$rideableassignments = [];
$routesections = [];
if ($selected) {
    $a = $selected['assignment'];
    $status = $selected['status'];
    $duration = $selected['duration'];
    $routepercent = $duration['soll'] > 0 ? min(100, (int) round(100 * $duration['ist'] / $duration['soll'])) : ($status['complete'] ? 100 : 0);
    echo html_writer::start_div('rv-card ' . ($status['complete'] ? 'is-done' : ''));
    echo html_writer::div(html_writer::tag('h3', '🛤️ ' . $learning::route_label($a)) .
        html_writer::span(get_string(!empty($a->waived) ? 'waived_label' : ($status['complete'] ? 'status_done' : 'status_open'), 'local_railvieweradmin'),
            'badge ' . (!empty($a->waived) ? 'badge-secondary' : ($status['complete'] ? 'badge-success' : 'badge-warning'))), 'rv-card-head');
    if (!empty($a->waived)) {
        echo html_writer::div(s((string) $a->waivereason), 'rv-muted');
    } else {
        echo html_writer::div('<span style="width:' . $routepercent . '%"></span>', 'rv-bar' . ($status['complete'] ? ' is-done' : ''));
        echo html_writer::div(\local_railvieweradmin\ui::duration_line($duration), 'rv-muted');
        echo \local_railvieweradmin\ui::video_line($status);
        echo \local_railvieweradmin\ui::missing_list($status);

        // Ersetzungsregeln mit Nutzerentscheidung.
        $rules = $DB->get_records('railviewer_route_substs', ['routeid' => $a->routeid], 'sortorder ASC');
        $allmethods = $learning::methods(false);
        $appliedmap = $DB->get_records_menu('railviewer_assignment_subst', ['assignmentid' => $a->id], '', 'substid, applied');
        foreach ($rules as $rule) {
            if (empty($rule->userchoice) || empty($allmethods[$rule->methodid]) || empty($allmethods[$rule->bymethodid]) || !isset($status['methods'][$rule->methodid])) {
                continue;
            }
            $label = get_string('subst_apply_label', 'local_railvieweradmin', (object) [
                'a' => format_string($allmethods[$rule->methodid]->name), 'n' => (int) $rule->factor,
                'b' => format_string($allmethods[$rule->bymethodid]->name)]);
            $on = !empty($appliedmap[$rule->id]);
            $form = '';
            if (empty($a->completedtime)) {
                $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $self->out(false)]);
                foreach (['sesskey' => sesskey(), 'action' => 'subst', 'assignmentid' => $a->id, 'substid' => $rule->id, 'applied' => $on ? 0 : 1] as $k => $v) {
                    $form .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
                }
                $form .= html_writer::tag('button', get_string($on ? 'subst_disable' : 'subst_enable', 'local_railvieweradmin'),
                    ['type' => 'submit', 'class' => 'btn btn-sm ' . ($on ? 'btn-outline-secondary' : 'btn-outline-primary')]);
                $form .= html_writer::end_tag('form');
            }
            echo html_writer::div(html_writer::span(s($label) . ' – <strong>' . get_string($on ? 'subst_on' : 'subst_off', 'local_railvieweradmin') . '</strong>') . $form, 'rv-subst');
        }

        // ---- Raster: Spalten = Abschnitte, je Lernmethode ein Block mit einer Zeile pro Lernfahrt.
        $sections = array_values($learning::route_sections((int) $a->routeid));
        $colindex = [];
        foreach ($sections as $i => $sec) {
            $colindex[(int) $sec->id] = $i;
        }
        $req = $learning::assignment_requirements($a);

        // Vorhandene (bestätigte/ausstehende) Fahrten dieser Zuweisung als zusammenhängende Bereiche.
        $rideranges = [];
        foreach ($DB->get_records_sql(
            "SELECT r.id, r.methodid, r.ridedate, r.trainnumber, r.status, r.isexternal, r.trainername, r.evu,
                    t.firstname, t.lastname, t.firstnamephonetic, t.lastnamephonetic, t.middlename, t.alternatename
               FROM {railviewer_rides} r
          LEFT JOIN {user} t ON t.id = r.trainerid
              WHERE r.assignmentid = :aid AND r.status IN ('confirmed','pending')
           ORDER BY r.ridedate, r.id", ['aid' => $a->id]) as $r) {
            $cols = [];
            foreach ($DB->get_fieldset_select('railviewer_ride_sections', 'sectionid', 'rideid = ?', [$r->id]) as $sid) {
                if (isset($colindex[(int) $sid])) {
                    $cols[] = $colindex[(int) $sid];
                }
            }
            if (!$cols) {
                continue;
            }
            $r->from = min($cols);
            $r->to = max($cols);
            $rideranges[(int) $r->methodid][] = $r;
        }

        // Anzuzeigende Methoden: geforderte, durch Regeln beteiligte und solche mit Fahrten.
        $show = [];
        foreach ($status['methods'] as $mid => $unused) {
            $show[$mid] = true;
        }
        foreach ($rules as $rule) {
            $show[(int) $rule->methodid] = true;
            $show[(int) $rule->bymethodid] = true;
        }
        foreach (array_keys($rideranges) as $mid) {
            $show[$mid] = true;
        }
        $methodlist = array_filter($learning::methods(), function($m) use ($show) { return !empty($show[$m->id]); });
        foreach ($methodlist as $m) {
            $rideableassignments[$m->id] = format_string($m->name);
        }

        if (!$methodlist) {
            echo html_writer::div(get_string('no_rideable_routes', 'local_railvieweradmin'), 'rv-callout');
        } else {
            echo html_writer::tag('p', get_string('grid_hint', 'local_railvieweradmin'), ['class' => 'rv-muted mt-2 mb-1']);
            echo html_writer::start_div('', ['class' => 'rv-grid-wrap']);
            echo html_writer::start_tag('table', ['class' => 'rv-grid', 'id' => 'rv-grid', 'data-assignment' => $a->id]);
            echo html_writer::start_tag('thead') . html_writer::start_tag('tr');
            echo html_writer::tag('th', get_string('ride_method', 'local_railvieweradmin'), ['class' => 'rv-grid-first']);
            foreach ($sections as $sec) {
                echo html_writer::tag('th', s(format_string($sec->name)), ['title' => format_string($sec->name)]);
            }
            echo html_writer::end_tag('tr') . html_writer::end_tag('thead') . html_writer::start_tag('tbody');

            foreach ($methodlist as $m) {
                $mid = (int) $m->id;
                $need = (int) ($req[$mid] ?? 0);
                // Kopfzeile der Methode mit Stand je Abschnitt (bestätigt/gefordert).
                echo html_writer::start_tag('tr', ['class' => 'rv-method-head']);
                echo html_writer::tag('th', s(format_string($m->name)) . ($need ? html_writer::span(' · ' . get_string('grid_required_n', 'local_railvieweradmin', $need), 'rv-muted') : ''), ['class' => 'rv-grid-first']);
                foreach ($sections as $sec) {
                    $cell = $status['sections'][$sec->id]['cells'][$mid] ?? null;
                    if ($cell) {
                        $txt = $cell['confirmed'] . '/' . $cell['required'] . ($cell['substituted'] ? ' +' . $cell['substituted'] : '');
                        echo html_writer::tag('td', s($txt), ['class' => 'rv-stand ' . ($cell['complete'] ? 'is-ok' : 'is-missing')]);
                    } else {
                        echo html_writer::tag('td', '–', ['class' => 'rv-stand']);
                    }
                }
                echo html_writer::end_tag('tr');

                // Fahrten in Zeilen packen: erste Zeile, in der der Bereich noch frei ist.
                $rows = [];
                foreach ($rideranges[$mid] ?? [] as $r) {
                    $placed = false;
                    foreach ($rows as $ri => $occupied) {
                        $free = true;
                        for ($c = $r->from; $c <= $r->to; $c++) {
                            if (!empty($occupied[$c])) {
                                $free = false;
                                break;
                            }
                        }
                        if ($free) {
                            for ($c = $r->from; $c <= $r->to; $c++) {
                                $rows[$ri][$c] = $r;
                            }
                            $placed = true;
                            break;
                        }
                    }
                    if (!$placed) {
                        $row = [];
                        for ($c = $r->from; $c <= $r->to; $c++) {
                            $row[$c] = $r;
                        }
                        $rows[] = $row;
                    }
                }
                $rowcount = max($need, count($rows)) + 1; // immer eine freie Zeile für eine weitere Fahrt
                for ($ri = 0; $ri < $rowcount; $ri++) {
                    $label = $ri < $need ? get_string('grid_ride_n', 'local_railvieweradmin', $ri + 1) : get_string('grid_ride_extra', 'local_railvieweradmin');
                    echo html_writer::start_tag('tr', ['class' => 'rv-ride-row', 'data-row' => $mid . '_' . $ri, 'data-method' => $mid]);
                    echo html_writer::tag('th', $label, ['class' => 'rv-grid-first rv-ride-label' . ($ri < $need ? '' : ' is-extra')]);
                    $c = 0;
                    $row = $rows[$ri] ?? [];
                    while ($c < count($sections)) {
                        if (!empty($row[$c])) {
                            $r = $row[$c];
                            $span = $r->to - $r->from + 1;
                            $who = !empty($r->isexternal) ? $r->trainername . ' (' . $r->evu . ')' : fullname($r);
                            $title = userdate($r->ridedate, get_string('strftimedatefullshort', 'langconfig')) . ' · ' . $r->trainnumber . ' · ' . $who
                                . ' · ' . get_string('ride_status_' . $r->status, 'local_railvieweradmin');
                            $whoshort = !empty($r->isexternal) ? $r->trainername : fullname($r);
                            echo html_writer::tag('td', html_writer::div(s(userdate($r->ridedate, '%d.%m.') . ' · ' . $r->trainnumber), 'rv-ride-main')
                                . html_writer::div(s($whoshort), 'rv-ride-who'),
                                ['colspan' => $span, 'class' => 'rv-ride is-' . $r->status, 'title' => $title]);
                            $c += $span;
                        } else {
                            echo html_writer::tag('td', '', ['class' => 'rv-free', 'data-col' => $c, 'data-section' => $sections[$c]->id,
                                'data-method' => $mid]);
                            $c++;
                        }
                    }
                    echo html_writer::end_tag('tr');
                }
            }
            echo html_writer::end_tag('tbody') . html_writer::end_tag('table') . html_writer::end_div();
            $routesections[$a->id] = array_map(function($sec) { return ['id' => (int) $sec->id, 'name' => format_string($sec->name)]; }, $sections);
        }

        // ---- Erfassungsformular: erscheint, sobald im Raster Zellen markiert wurden.
        if ($methodlist) {
            echo html_writer::start_div('rv-card rv-capture', ['id' => 'rv-capture', 'style' => 'display:none']);
            echo html_writer::tag('h4', get_string('add_ride', 'local_railvieweradmin'), ['style' => 'margin-top:0']);
            echo html_writer::div('', 'rv-selection', ['id' => 'rv-selection']);
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => $self->out(false), 'id' => 'rideform', 'class' => 'rv-form']);
            foreach (['sesskey' => sesskey(), 'action' => 'addride', 'assignmentid' => $a->id, 'scope' => 'part'] as $k => $v) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'value' => $v]);
            }
            foreach (['methodid' => 'r-method', 'fromsectionid' => 'r-from', 'tosectionid' => 'r-to'] as $k => $id) {
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $k, 'id' => $id, 'value' => '']);
            }
            $field = function ($label, $inputhtml, $id) {
                return html_writer::div(html_writer::label($label, $id) . $inputhtml, 'rv-field');
            };
            echo html_writer::div(
                $field(get_string('ride_date', 'local_railvieweradmin'),
                    html_writer::empty_tag('input', ['type' => 'date', 'name' => 'ridedate', 'id' => 'r-date', 'class' => 'form-control',
                        'required' => 'required', 'max' => date('Y-m-d'), 'value' => date('Y-m-d')]), 'r-date') .
                $field(get_string('ride_train', 'local_railvieweradmin'),
                    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'trainnumber', 'id' => 'r-train', 'class' => 'form-control',
                        'required' => 'required', 'maxlength' => 30]), 'r-train'), 'rv-row');

            echo html_writer::start_div('', ['id' => 'r-internalbox']);
            echo $field(get_string('ride_trainer', 'local_railvieweradmin'),
                html_writer::empty_tag('input', ['type' => 'text', 'id' => 'r-trainer-q', 'class' => 'form-control', 'autocomplete' => 'off',
                    'placeholder' => get_string('ride_trainer_placeholder', 'local_railvieweradmin')]) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'trainerid', 'id' => 'r-trainer-id', 'value' => '']) .
                html_writer::div('', 'list-group', ['id' => 'r-trainer-results']) .
                html_writer::div('', 'text-success', ['id' => 'r-trainer-selected']), 'r-trainer-q');
            echo html_writer::end_div();

            echo html_writer::div(html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'external', 'value' => 1, 'id' => 'r-external']) .
                html_writer::label(get_string('ride_external', 'local_railvieweradmin'), 'r-external', true, ['style' => 'font-weight:400']), 'rv-checkline');
            echo html_writer::start_div('', ['id' => 'r-externalbox', 'style' => 'display:none']);
            echo $field(get_string('ride_evu', 'local_railvieweradmin'), html_writer::empty_tag('input', ['type' => 'text', 'name' => 'evu', 'id' => 'r-evu', 'class' => 'form-control', 'maxlength' => 255]), 'r-evu');
            echo $field(get_string('ride_trainername', 'local_railvieweradmin'), html_writer::empty_tag('input', ['type' => 'text', 'name' => 'trainername', 'id' => 'r-tname', 'class' => 'form-control', 'maxlength' => 255]), 'r-tname');
            echo html_writer::div(html_writer::label(get_string('ride_signature', 'local_railvieweradmin'), 'r-sig') .
                html_writer::div(html_writer::tag('canvas', '', ['id' => 'r-sig', 'width' => 480, 'height' => 160, 'style' => 'background:#fff;touch-action:none;display:block']), 'rv-sig-box') .
                html_writer::tag('button', get_string('ride_signature_clear', 'local_railvieweradmin'), ['type' => 'button', 'id' => 'r-sig-clear', 'class' => 'btn btn-sm btn-outline-secondary mt-1']) .
                html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'signature', 'id' => 'r-sig-data', 'value' => '']), 'rv-field');
            echo html_writer::end_div();

            echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('ride_save', 'local_railvieweradmin')]);
            echo ' ' . html_writer::tag('button', get_string('cancel'), ['type' => 'button', 'id' => 'rv-cancel', 'class' => 'btn btn-secondary']);
            echo html_writer::end_tag('form');
            echo html_writer::end_div();

            $selectionlabel = json_encode(get_string('grid_selection', 'local_railvieweradmin'));
            $js = 'window.addEventListener("load", function() {
                var $ = function(id) { return document.getElementById(id); };
                var grid = $("rv-grid"), capture = $("rv-capture");
                var methodNames = ' . json_encode($rideableassignments) . ';
                var sections = ' . json_encode(array_values($routesections[$a->id] ?? [])) . ';
                if (!grid) { return; }
                var selLabel = ' . $selectionlabel . ';
                var start = null, current = null, dragging = false;

                function clearSel() { grid.querySelectorAll(".rv-free.is-selected").forEach(function(c) { c.classList.remove("is-selected"); }); }
                function rowCells(row) {
                    var map = {};
                    row.querySelectorAll(".rv-free").forEach(function(c) { map[c.dataset.col] = c; });
                    return map;
                }
                function cellFromPoint(x, y) {
                    var el = document.elementFromPoint(x, y);
                    return el && el.closest ? el.closest(".rv-free") : null;
                }
                function paint(to) {
                    clearSel();
                    var map = rowCells(start.parentNode), anchor = parseInt(start.dataset.col, 10), l = anchor, r = anchor, c;
                    if (to >= anchor) { for (c = anchor; c <= to && map[c]; c++) { r = c; } }
                    else { for (c = anchor; c >= to && map[c]; c--) { l = c; } }
                    for (var k = l; k <= r; k++) { map[k].classList.add("is-selected"); }
                    return [l, r];
                }
                var range = null;
                grid.addEventListener("pointerdown", function(e) {
                    var c = e.target.closest ? e.target.closest(".rv-free") : null;
                    if (!c) { return; }
                    e.preventDefault();
                    start = c; dragging = true;
                    range = paint(parseInt(c.dataset.col, 10));
                });
                grid.addEventListener("pointermove", function(e) {
                    if (!dragging) { return; }
                    var c = cellFromPoint(e.clientX, e.clientY);
                    if (c && c.parentNode === start.parentNode) { range = paint(parseInt(c.dataset.col, 10)); }
                });
                window.addEventListener("pointerup", function() {
                    if (!dragging) { return; }
                    dragging = false;
                    if (!range) { return; }
                    var mid = start.dataset.method;
                    $("r-method").value = mid;
                    $("r-from").value = sections[range[0]].id;
                    $("r-to").value = sections[range[1]].id;
                    var txt = methodNames[mid] + ": " + sections[range[0]].name + (range[0] === range[1] ? "" : " → " + sections[range[1]].name);
                    $("rv-selection").textContent = selLabel + " " + txt;
                    capture.style.display = "block";
                    capture.scrollIntoView({behavior: "smooth", block: "nearest"});
                });
                $("rv-cancel").addEventListener("click", function() { clearSel(); capture.style.display = "none"; range = null; });

                $("r-external").addEventListener("change", function() {
                    $("r-externalbox").style.display = this.checked ? "block" : "none";
                    $("r-internalbox").style.display = this.checked ? "none" : "block";
                });
                var timer = null, seq = 0;
                $("r-trainer-q").addEventListener("input", function() {
                    $("r-trainer-id").value = ""; $("r-trainer-selected").textContent = "";
                    var q = this.value.trim(); clearTimeout(timer);
                    if (q.length < 2) { $("r-trainer-results").innerHTML = ""; return; }
                    timer = setTimeout(function() {
                        var mine = ++seq;
                        fetch(M.cfg.wwwroot + "/mod/railviewer/search_users.php?sesskey=" + M.cfg.sesskey + "&q=" + encodeURIComponent(q))
                            .then(function(r) { return r.json(); })
                            .then(function(list) {
                                if (mine !== seq) { return; }
                                var box = $("r-trainer-results"); box.innerHTML = "";
                                list.forEach(function(u) {
                                    var b = document.createElement("button"); b.type = "button"; b.className = "list-group-item list-group-item-action";
                                    b.textContent = u.name;
                                    b.addEventListener("click", function() {
                                        $("r-trainer-id").value = u.id; $("r-trainer-q").value = u.name;
                                        $("r-trainer-selected").textContent = "✓ " + u.name; box.innerHTML = "";
                                    });
                                    box.appendChild(b);
                                });
                            });
                    }, 250);
                });
                var cv = $("r-sig"), ctx = cv.getContext("2d"), drawing = false, drawn = false;
                ctx.lineWidth = 2; ctx.lineCap = "round"; ctx.strokeStyle = "#000";
                function pos(e) { var r = cv.getBoundingClientRect(); return {x: (e.clientX - r.left) * cv.width / r.width, y: (e.clientY - r.top) * cv.height / r.height}; }
                cv.addEventListener("pointerdown", function(e) { drawing = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); cv.setPointerCapture(e.pointerId); });
                cv.addEventListener("pointermove", function(e) { if (!drawing) { return; } var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); drawn = true; });
                ["pointerup", "pointercancel"].forEach(function(n) { cv.addEventListener(n, function() { drawing = false; }); });
                $("r-sig-clear").addEventListener("click", function() { ctx.clearRect(0, 0, cv.width, cv.height); drawn = false; });
                $("rideform").addEventListener("submit", function(e) {
                    if (!$("r-from").value) { e.preventDefault(); return; }
                    if ($("r-external").checked) {
                        if (!drawn) { e.preventDefault(); alert(' . json_encode(get_string('ride_signature_required', 'local_railvieweradmin')) . '); return; }
                        $("r-sig-data").value = cv.toDataURL("image/png");
                    } else if (!$("r-trainer-id").value) {
                        e.preventDefault(); alert(' . json_encode(get_string('ride_invalid_trainer', 'local_railvieweradmin')) . ');
                    }
                });
            });';
            $PAGE->requires->js_init_code($js);
        }
    }
    echo html_writer::end_div(); // rv-card
}
echo html_writer::end_div(); // rv-main
echo html_writer::end_div(); // rv-layout

// ---- Meine Fahrten
$rides = $DB->get_records_sql(
    "SELECT r.*, m.name AS methodname, rt.name AS routename, rt.direction, rt.pairedrouteid, t.firstname, t.lastname, t.firstnamephonetic, t.lastnamephonetic, t.middlename, t.alternatename
       FROM {railviewer_rides} r
       JOIN {railviewer_methods} m ON m.id = r.methodid
       JOIN {railviewer_routes} rt ON rt.id = r.routeid
  LEFT JOIN {user} t ON t.id = r.trainerid
      WHERE r.userid = :me ORDER BY r.ridedate DESC, r.id DESC", ['me' => $USER->id]);
echo html_writer::tag('h3', get_string('my_rides_list', 'local_railvieweradmin'), ['class' => 'rv-section-title']);
if (!$rides) {
    echo html_writer::div(get_string('no_rides_yet', 'local_railvieweradmin'), 'rv-muted');
} else {
    $table = new html_table();
    $table->attributes['class'] = 'rv-table';
    $table->head = [get_string('ride_date', 'local_railvieweradmin'), get_string('route', 'local_railvieweradmin'),
        get_string('ride_method', 'local_railvieweradmin'), get_string('ride_train', 'local_railvieweradmin'),
        get_string('ride_trainer', 'local_railvieweradmin'), get_string('status', 'local_railvieweradmin')];
    foreach ($rides as $r) {
        $trainer = $r->isexternal ? s($r->trainername . ' (' . $r->evu . ')') : s(fullname($r));
        $table->data[] = [userdate($r->ridedate, get_string('strftimedatefullshort', 'langconfig')), $learning::route_label($r),
            format_string($r->methodname), s($r->trainnumber), $trainer,
            \local_railvieweradmin\ui::ride_status($r->status) . ($r->status === 'rejected' && $r->rejectreason ? ' ' . s($r->rejectreason) : '')];
    }
    echo html_writer::table($table);
}
echo html_writer::end_div(); // rv-rides
echo $OUTPUT->footer();
