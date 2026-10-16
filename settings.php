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

// mod_railviewer hat aktuell keine eigenen Einstellungen - die tatsächliche Konfiguration
// (Vimeo/ignite.video-API-Zugangsdaten, Overlay-Standardwerte, Anrede Du/Sie) liegt im
// zwingend benötigten Verwaltungsplugin local_railvieweradmin, von dem dieses Modul seine
// Strecken/Abschnitte/Zuweisungen bezieht. Diese Seite existiert primär, damit auf der
// Plugin-Übersicht ein Settings-Link erscheint, der direkt dorthin verweist.
if ($hassiteconfig) {
    $settings->add(new admin_setting_heading(
        'mod_railviewer/nosettings',
        get_string('pluginname', 'mod_railviewer'),
        get_string('settingsinfo', 'mod_railviewer', (new moodle_url('/local/railvieweradmin/index.php'))->out())
    ));
}
