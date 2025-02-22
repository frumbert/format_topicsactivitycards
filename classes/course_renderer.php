<?php

namespace format_topicsactivitycards;

use cm_info;
use completion_info;
use html_writer;
use moodle_url;
use pix_icon;
use stdClass;

class course_renderer extends \core_course_renderer {


    /**
     * Renders HTML to display a list of course modules in a course section
     * Also displays "move here" controls in Javascript-disabled mode
     *
     * This function calls {@link core_course_renderer::course_section_cm()}
     *
     * @param stdClass $course course object
     * @param int|stdClass|section_info $section relative section number or section object
     * @param int $sectionreturn section number to return to
     * @param int $displayoptions
     * @return void
     */
    public function course_section_cm_list($course, $section, $sectionreturn = null, $displayoptions = array()) {
        global $USER, $DB;

        $output = '';
        $modinfo = get_fast_modinfo($course);
        if (is_object($section)) {
            $section = $modinfo->get_section_info($section->section);
        } else {
            $section = $modinfo->get_section_info($section);
        }
        $completioninfo = new completion_info($course);

        // check if we are currently in the process of moving a module with JavaScript disabled
        $ismoving = $this->page->user_is_editing() && ismoving($course->id);
        if ($ismoving) {
            $movingpix = new pix_icon('movehere', get_string('movehere'), 'moodle', array('class' => 'movetarget'));
            $strmovefull = strip_tags(get_string("movefull", "", "'$USER->activitycopyname'"));
        }

        $displayoptions['durations'] = [];
        if ($field = $DB->get_record('local_metadata_field', ['shortname' => 'duration', 'contextlevel' => CONTEXT_MODULE])) {
            $displayoptions['durationfield'] = $field;
            $cm_infos = $modinfo->get_cms();
            if (!empty($cm_infos)) {
                list($insql, $params) = $DB->get_in_or_equal(array_keys($cm_infos), SQL_PARAMS_NAMED);
                $sql = "instanceid $insql AND fieldid = :fieldid";
                $params['fieldid'] = $field->id;
                ;
                $durationsraw = $DB->get_records_select('local_metadata', $sql, $params);

                foreach ($durationsraw as $durationraw) {
                    $displayoptions['durations'][$durationraw->instanceid] = $durationraw;
                }
            }
        }

        $displayoptions['cardimages'] = [];
        if ($field = $DB->get_record('local_metadata_field', ['shortname' => 'cardimage', 'contextlevel' => CONTEXT_MODULE])) {
            $contexts = \context_course::instance($course->id)->get_child_contexts();
            $contextids = array_keys($contexts);
            $filerecords = $this->get_area_files($contextids, 'metadatafieldtype_file', 'cardimage');

            foreach ($filerecords as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $imageurl = moodle_url::make_pluginfile_url($file->get_contextid(),
                        $file->get_component(), $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());
                $imageurl = $imageurl->out();

                $displayoptions['cardimages'][$file->get_itemid()] = $imageurl;
            }
        }


        // Get the list of modules visible to user (excluding the module being moved if there is one)
        $moduleshtml = array();
        if (!empty($modinfo->sections[$section->section])) {
            foreach ($modinfo->sections[$section->section] as $modnumber) {
                $mod = $modinfo->cms[$modnumber];

                if ($ismoving and $mod->id == $USER->activitycopy) {
                    // do not display moving mod
                    continue;
                }

                if ($modulehtml = $this->course_section_cm_list_item($course,
                        $completioninfo, $mod, $sectionreturn, $displayoptions)) {
                    $moduleshtml[$modnumber] = $modulehtml;
                }
            }
        }

        $sectionoutput = '';
        if (!empty($moduleshtml) || $ismoving) {
            foreach ($moduleshtml as $modnumber => $modulehtml) {
                if ($ismoving) {
                    $movingurl = new moodle_url('/course/mod.php', array('moveto' => $modnumber, 'sesskey' => sesskey()));
                    $sectionoutput .= html_writer::tag('li',
                            html_writer::link($movingurl, $this->output->render($movingpix), array('title' => $strmovefull)),
                            array('class' => 'movehere'));
                }

                $sectionoutput .= $modulehtml;
            }

            if ($ismoving) {
                $movingurl = new moodle_url('/course/mod.php', array('movetosection' => $section->id, 'sesskey' => sesskey()));
                $sectionoutput .= html_writer::tag('li',
                        html_writer::link($movingurl, $this->output->render($movingpix), array('title' => $strmovefull)),
                        array('class' => 'movehere'));
            }
        }

        // Always output the section module list.
        $output .= html_writer::tag('div', $sectionoutput, array('class' => 'section img-text card-deck'));

        return $output;
    }

    /**
     * Renders HTML to display one course module for display within a section.
     *
     * This function calls:
     * {@link core_course_renderer::course_section_cm()}
     *
     * @param stdClass $course
     * @param completion_info $completioninfo
     * @param cm_info $mod
     * @param int|null $sectionreturn
     * @param array $displayoptions
     * @return String
     */
    public function course_section_cm_list_item($course, &$completioninfo, cm_info $mod, $sectionreturn, $displayoptions = array()) {
        $output = '';
        if ($modulehtml = $this->course_section_cm($course, $completioninfo, $mod, $sectionreturn, $displayoptions)) {
            $output = $modulehtml;
        }
        return $output;
    }

    /**
     * Renders HTML to display one course module for display within a section.
     *
     * This function calls:
     * {@link core_course_renderer::course_section_cm()}
     *
     * @param stdClass $course
     * @param completion_info $completioninfo
     * @param cm_info $mod
     * @param int|null $sectionreturn
     * @param array $displayoptions
     * @return String
     */
    public function course_section_cm($course, &$completioninfo, cm_info $mod, $sectionreturn,
            $displayoptions = array()) {
        global $OUTPUT, $PAGE, $USER;

        $unstyledmodules = ['label'];

        if (!$mod->is_visible_on_course_page()) {
            return '';
        }

        $template = new stdClass();
        $template->mod = $mod;

        if (in_array($mod->modname, $unstyledmodules)) {
            $template->unstyled = true;
        }

        // Fetch activity dates.
        $activitydates = [];
        if ($course->showactivitydates) {
            $activitydates = \core\activity_dates::get_dates_for_module($mod, $USER->id);
        }

        // Fetch completion details.
        $showcompletionconditions = $course->showcompletionconditions == COMPLETION_SHOW_CONDITIONS;
        $completiondetails = \core_completion\cm_completion_details::get_instance($mod, $USER->id, $showcompletionconditions);
        $ismanualcompletion = $completiondetails->has_completion() && !$completiondetails->is_automatic();

        $template->text = $mod->get_formatted_content(array('overflowdiv' => false, 'noclean' => true));

        $template->showcompletion = ($showcompletionconditions || $ismanualcompletion || $activitydates);
        $template->completion = $this->output->activity_information($mod, $completiondetails, $activitydates);

        $template->cmname = $this->course_section_cm_name($mod, $displayoptions);
        $template->editing = $PAGE->user_is_editing();
        $template->availability = $this->course_section_cm_availability($mod, $displayoptions);

        if ($PAGE->user_is_editing()) {
            $editactions = course_get_cm_edit_actions($mod, $mod->indent, $sectionreturn);
            $template->editoptions = $this->course_section_cm_edit_actions($editactions, $mod, $displayoptions);
            $template->editoptions .= $mod->afterediticons;
            $template->moveicons = course_get_cm_move($mod, $sectionreturn);
        }

        if (!empty($displayoptions['durations'][$mod->id])) {

            $class = 'durationfield_timeunit';
            $str = new stdClass();
            $str->day   = html_writer::span(get_string('day'), $class);
            $str->days  = html_writer::span(get_string('days'), $class);
            $str->hour  = html_writer::span(get_string('hour'), $class);
            $str->hours = html_writer::span(get_string('hours'), $class);
            $str->min   = html_writer::span(get_string('min'), $class);
            $str->mins  = html_writer::span(get_string('mins'), $class);
            $str->sec   = html_writer::span(get_string('sec'), $class);
            $str->secs  = html_writer::span(get_string('secs'), $class);
            $str->year  = html_writer::span(get_string('year'), $class);
            $str->years = html_writer::span(get_string('years'), $class);

            $template->duration = html_writer::span($displayoptions['durationfield']->name . ": ", 'durationfield_fieldname') . format_time($displayoptions['durations'][$mod->id]->data, $str);
        }

        if (!empty($displayoptions['cardimages'][$mod->id])) {
            $template->cardimage = $displayoptions['cardimages'][$mod->id];
        }

        $template->showheader = (!empty($template->editing) || !empty($template->cardimage));
        $template->showfooter = (!empty($template->completion) || !empty($template->availability) || !empty($template->duration));

        return $this->render_from_template('format_topicsactivitycards/coursemodule', $template);
    }

    public function get_area_files($contextids, $component, $filearea) {
        global $DB;

        $fs = get_file_storage();
        if (empty($contextids)) return []; // no topics; nothing to do
        list($contextidsql, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
        $params['filearea'] = $filearea;
        $params['component'] = $component;

        $sql = "SELECT ".self::instance_sql_fields('f', 'r')."
                  FROM {files} f
             LEFT JOIN {files_reference} r
                       ON f.referencefileid = r.id
                 WHERE f.contextid $contextidsql
                       AND f.component = :component
                       AND f.filearea  = :filearea";

        $result = array();
        $filerecords = $DB->get_records_sql($sql, $params);
        foreach ($filerecords as $filerecord) {
            $result[$filerecord->pathnamehash] = $fs->get_file_instance($filerecord);
        }
        return $result;
    }

    private static function instance_sql_fields($filesprefix, $filesreferenceprefix) {
        // Note, these fieldnames MUST NOT overlap between the two tables,
        // else problems like MDL-33172 occur.
        $filefields = array('contenthash', 'pathnamehash', 'contextid', 'component', 'filearea',
                'itemid', 'filepath', 'filename', 'userid', 'filesize', 'mimetype', 'status', 'source',
                'author', 'license', 'timecreated', 'timemodified', 'sortorder', 'referencefileid');

        $referencefields = array('repositoryid' => 'repositoryid',
                                 'reference' => 'reference',
                                 'lastsync' => 'referencelastsync');

        // id is specifically named to prevent overlaping between the two tables.
        $fields = array();
        $fields[] = $filesprefix.'.id AS id';
        foreach ($filefields as $field) {
            $fields[] = "{$filesprefix}.{$field}";
        }

        foreach ($referencefields as $field => $alias) {
            $fields[] = "{$filesreferenceprefix}.{$field} AS {$alias}";
        }

        return implode(', ', $fields);
    }
}
