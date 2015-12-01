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
 * @package    mod
 * @subpackage hvp
 * @copyright  2013 Amendor
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once ($CFG->dirroot . '/mod/hvp/hvp.php');

function hvp_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                  return true;
        case FEATURE_GROUPINGS:               return true;
        case FEATURE_GROUPMEMBERSONLY:        return true;
        case FEATURE_MOD_INTRO:               return false;
        case FEATURE_COMPLETION_TRACKS_VIEWS: return false;
        case FEATURE_COMPLETION_HAS_RULES:    return false;
        case FEATURE_GRADE_HAS_GRADE:         return false;
        case FEATURE_GRADE_OUTCOMES:          return false;
        case FEATURE_BACKUP_MOODLE2:          return false;
        case FEATURE_SHOW_DESCRIPTION:        return false;

        default: return null;
    }
}

/**
 * Implements Moodle's MODULENAME_add_instance()
 *
 * When a new activity is added on a course, Moodle creates course module,
 * module instance, add the module to the correct section. Moodle calls
 * this function to create the module instance.
 *
 * @param object $moduleinfo the module data
 * @return int $cmid Course module instance id (id of 'hvp' table)
 */
function hvp_add_instance($moduleinfo) {
    global $DB;

    // Moodle expects the MODULENAME_add_instance() to return the id, so we
    // need to save the data here manually because savePackage() does not
    // return the id.
    $cmid = $DB->insert_record('hvp', (object) array(
        'name' => $moduleinfo->name,
        'course' => $moduleinfo->course,
        'json_content' => '',
        'main_library_id' => '',
    ));

    $h5pStorage = hvp_get_instance('storage');
    $h5pStorage->savePackage(array('id' => $cmid));

    return $cmid;
}

function hvp_update_instance($hvp) {
    global $DB;

    $hvp->id = $hvp->instance;
    $result = $DB->update_record('hvp', $hvp);

    $h5pStorage = hvp_get_instance('storage');
    $library_updated = $h5pStorage->updatePackage($hvp->id);

    return $result;
}

function hvp_delete_instance($id) {
    global $DB;

    if (! $hvp = $DB->get_record('hvp', array('id' => "$id"))) {
        return false;
    }

    $result = true;

    $h5pStorage = hvp_get_instance('storage');
    $h5pStorage->deletePackage($hvp->id);

    if (! $DB->delete_records('hvp', array('id' => "$hvp->id"))) {
        $result = false;
    }

    debugging('Deleted h5p ' . $hvp->id . ': ' . $result, DEBUG_DEVELOPER);
    return $result;
}

function hvp_get_hvp($hvpid) {
    global $DB;

    $hvp = $DB->get_record_sql('
      SELECT
        h.id,
        h.name,
        h.json_content,
        h.embed_type,
        h.main_library_id,
        hl.machine_name,
        hl.major_version,
        hl.minor_version,
        hl.embed_types,
        hl.fullscreen
      FROM {hvp} h
      JOIN {hvp_libraries} hl ON hl.id = h.main_library_id
      WHERE h.id = ?', array($hvpid));

    if ($hvp) {
        return $hvp;
    }

    return false;
}

function hvp_get_file_paths($hvp) {
    global $CFG, $DB;

    $filepaths = array(
        'preloadedJs' => array(),
        'preloadedCss' => array(),
    );

    $libraries = $DB->get_records_sql(
      'SELECT
        hl.id,
        hl.machine_name,
        hl.major_version,
        hl.minor_version,
        hl.preloaded_css,
        hl.preloaded_js,
        hcl.drop_css
      FROM {hvp_contents_libraries} hcl
      JOIN {hvp_libraries} hl ON hcl.library_id = hl.id
      WHERE hcl.id = ?', array($hvp->id));

    $path = '/mod/hvp/files';
    $h5pcore = hvp_get_instance('core');
    foreach ($libraries as $library) {
        // core only supports assoc. arrays.
        $librarydata = array(
          'machineName' => $library->machine_name,
          'majorVersion' => $library->major_version,
          'minorVersion' => $library->minor_version,
        );
        if (!empty($library->preloaded_js)) {
            foreach (explode(',', $library->preloaded_js) as $scriptpath) {
                $filepaths['preloadedJs'][] = $path . '/libraries/' . $h5pcore->libraryToString($librarydata, TRUE) . '/' . trim($scriptpath);
            }
        }
        if (!empty($library->preloaded_css) && !$library->drop_css) {
            foreach (explode(',', $library->preloaded_css) as $stylepath) {
                $filepaths['preloadedCss'][] = $path . '/libraries/' . $h5pcore->libraryToString($librarydata, TRUE) . '/' . trim($stylepath);
            }
        }

    }

    return $filepaths;
}

function hvp_add_scripts_and_styles($hvp, $embedtype) {
    global $CFG, $PAGE;

    foreach (H5PCore::$styles as $style) {
        $PAGE->requires->css('/mod/hvp/library/' . $style);
    }
    $PAGE->requires->js('/mod/hvp/hvp.js', true);
    $PAGE->requires->string_for_js('fullscreen', 'hvp');
    foreach (H5PCore::$scripts as $script) {
        $PAGE->requires->js('/mod/hvp/library/' . $script, true);
    }

    $settings = array(
        'content' => array(
            'cid-' . $hvp->id => array(
                'jsonContent' => $hvp->json_content,
                'fullScreen' => $hvp->fullscreen
            ),
        ),
        'contentPath' => $CFG->wwwroot . '/mod/hvp/files/content/',
        'exportEnabled' => FALSE,
        'libraryPath' => $CFG->wwwroot . '/mod/hvp/files/libraries/',
    );

    $filepaths = hvp_get_file_paths($hvp);
    foreach ($filepaths['preloadedJs'] as $script) {
        $PAGE->requires->js($script, true);
        $settings['loadedJs'][] = $CFG->wwwroot . $script;
    }

    if ($embedtype === 'div') {
        foreach ($filepaths['preloadedCss'] as $style) {
            $PAGE->requires->css($style);
            $settings['loadedCss'][] = $CFG->wwwroot . $style;
        }
    }
    else {
        $settings['core']['scripts'] = array();
        $settings['core']['styles'] = array();
        foreach (H5PCore::$styles as $style) {
            $settings['core']['styles'][] = '/mod/hvp/library/' . $style;
        }
        $settings['core']['scripts'][] = '/mod/hvp/hvp.js';
        foreach (H5PCore::$scripts as $script) {
            $settings['core']['scripts'][] = '/mod/hvp/library/' . $script;
        }


        $settings['content']['cid-' . $hvp->id]['scripts'] = $filepaths['preloadedJs'];
        $settings['content']['cid-' . $hvp->id]['styles'] = $filepaths['preloadedCss'];
    }

    $PAGE->requires->data_for_js('hvp', $settings, true);
}
