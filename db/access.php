<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = array(

    'mod/railviewer:view' => array(
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => array(
            // Kein 'guest'-Zugriff: railviewer_assignments ist an konkrete Nutzer-IDs
            // gebunden, Gäste können daher ohnehin keinen sinnvollen Fortschritt haben.
            'student' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ),
    ),

    'mod/railviewer:addinstance' => array(
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => array(
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW
        ),
        'clonepermissionsfrom' => 'moodle/course:manageactivities'
    ),

);

