<?php
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
