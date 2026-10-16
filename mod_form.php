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

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_railviewer_mod_form extends moodleform_mod {
    public function definition() {
        global $DB;
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('railviewername', 'mod_railviewer'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Standardmäßig ist eine Aktivität ein genereller Player: sie zeigt ALLE dem Nutzer
        // zugewiesenen Routen an, unabhängig davon, wo/wie sie zugewiesen wurden (Routen werden
        // in der Railviewer-Streckenverwaltung unabhängig von Kursaktivitäten vergeben - es
        // muss nicht für jede zugewiesene Route eine eigene Aktivität angelegt werden). Optional
        // kann eine Kursleitung die Aktivität stattdessen fest an genau eine Route binden, z.B.
        // um sie gezielt in ein bestimmtes Kursthema einzubetten.
        //
        // Bewusst als Checkbox + abhängiges Auswahlfeld statt eines Auswahlfelds mit einer
        // "leeren" Option: ein Select-Wert von "" wird von PARAM_INT beim Speichern ohnehin zu
        // 0, was beim erneuten Öffnen des Formulars leicht zu Verwechslungen führt (welcher der
        // beiden Werte bedeutet jetzt "kein Filter"?). Die Checkbox macht den generischen
        // Zustand explizit und wird in data_postprocessing() zusätzlich hart erzwungen, damit
        // ein eventuell trotzdem übermittelter alter routeid-Wert im Auswahlfeld nicht
        // versehentlich durchschlägt.
        $mform->addElement('advcheckbox', 'restricttoroute',
            get_string('restricttoroute', 'mod_railviewer'), get_string('restricttoroute_label', 'mod_railviewer'));
        $mform->setDefault('restricttoroute', 0);
        $mform->addHelpButton('restricttoroute', 'restricttoroute', 'mod_railviewer');

        $routes = $DB->get_records_menu('railviewer_routes', null, 'name ASC', 'id, name');
        $mform->addElement('select', 'routeid', get_string('route', 'mod_railviewer'), $routes);
        $mform->setType('routeid', PARAM_INT);
        $mform->hideIf('routeid', 'restricttoroute', 'notchecked');

        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }

    /**
     * Setzt beim Öffnen des Bearbeitungsformulars die Checkbox passend zum gespeicherten
     * routeid-Wert (0/NULL = generisch = Checkbox aus, sonst an).
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        $defaultvalues['restricttoroute'] = !empty($defaultvalues['routeid']) ? 1 : 0;
    }

    /**
     * Erzwingt routeid = 0, wenn die Checkbox nicht gesetzt ist - unabhängig davon, welchen
     * (ggf. veralteten) Wert das versteckte Auswahlfeld dabei mit übermittelt hat.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (empty($data->restricttoroute)) {
            $data->routeid = 0;
        }
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        if (!empty($data['restricttoroute'])
                && (empty($data['routeid']) || !$DB->record_exists('railviewer_routes', ['id' => $data['routeid']]))) {
            $errors['routeid'] = get_string('invalidroute', 'mod_railviewer');
        }

        return $errors;
    }
}
