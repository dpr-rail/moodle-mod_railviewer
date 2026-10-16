<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_railviewer';
$plugin->version = 2026101100;
$plugin->requires = 2017111300; // Totara 19/20 basiert auf Moodle 3.4.
$plugin->cron = 0;
$plugin->dependencies = ['local_railvieweradmin' => 202610110013];
$plugin->release = '0.6';
$plugin->maturity = MATURITY_ALPHA;
