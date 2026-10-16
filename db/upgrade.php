<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade-Schritte für mod_railviewer.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_railviewer_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091300) {
        $table = new xmldb_table('railviewer');

        $field = new xmldb_field('introformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'intro');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // routeid: welche Strecke (local_railvieweradmin.railviewer_routes) diese Instanz zeigt.
        // NOTNULL=false und ohne automatische Befüllung, da eine bestehende Instanz nicht
        // zuverlässig automatisch einer Route zugeordnet werden kann - das muss der Admin
        // einmalig über das Bearbeitungsformular der Aktivität nachtragen.
        $field = new xmldb_field('routeid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'introformat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026091300, 'railviewer');
    }

    return true;
}
