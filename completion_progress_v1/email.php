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
 * Completion Progress block overview page
 *
 * @package    block_completion_progress
 * @copyright  2018 Michael de Raadt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Include required files.
require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot.'/blocks/completion_progress/lib.php');
require_once($CFG->dirroot.'/notes/lib.php');
require_once($CFG->libdir.'/tablelib.php');

/**
 * Default number of participants per page.
 */
const DEFAULT_PAGE_SIZE = 20;

/**
 * An impractically high number of participants indicating 'all' are to be shown.
 */
const SHOW_ALL_PAGE_SIZE = 5000;

// Gather form data.
$instanceid = required_param('instanceid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$page     = optional_param('page', 0, PARAM_INT); // Which page to show.
$perpage  = optional_param('perpage', DEFAULT_PAGE_SIZE, PARAM_INT); // How many per page.
$group    = optional_param('group', 0, PARAM_ALPHANUMEXT); // Group selected.
$email    = optional_param('email', '', PARAM_TEXT); // sender.
//$userto   = optional_param('userto', PARAM_INT);
$studentid = required_param('studentid', PARAM_INT);


// Determine course and context.
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($courseid);

$notesallowed = !empty($CFG->enablenotes) && has_capability('moodle/notes:manage', $context);
$messagingallowed = !empty($CFG->messaging) && has_capability('moodle/site:sendmessage', $context);
$bulkoperations = ($CFG->version >= 2017111300.00) &&
    has_capability('moodle/course:bulkmessaging', $context) && (
        $notesallowed || $messagingallowed
    );

// Find the role to display, defaulting to students.
$sql = "SELECT DISTINCT r.id, r.name, r.archetype
          FROM {role} r, {role_assignments} a
         WHERE a.contextid = :contextid
           AND r.id = a.roleid
           AND r.archetype = :archetype";
$params = array('contextid' => $context->id, 'archetype' => 'student');
$studentrole = $DB->get_record_sql($sql, $params);
if ($studentrole) {
    $studentroleid = $studentrole->id;
} else {
    $studentroleid = 0;
}
$roleselected = optional_param('role', $studentroleid, PARAM_INT);

// Get specific block config and context.
$block = $DB->get_record('block_instances', array('id' => $instanceid), '*', MUST_EXIST);
$config = unserialize(base64_decode($block->configdata));
$blockcontext = context_block::instance($instanceid);

// Set up page parameters.
$PAGE->set_course($course);
$PAGE->set_url(
    '/blocks/completion_progress/overview.php',
    array(
        'instanceid' => $instanceid,
        'courseid'   => $courseid,
        'page'       => $page,
        'perpage'    => $perpage,
        'group'      => $group,
        'sesskey'    => sesskey(),
        'role'       => $roleselected,
    )
);
$PAGE->set_context($context);
$title = get_string('overview', 'block_completion_progress');
$PAGE->set_title($title);
$PAGE->set_heading($title);
$PAGE->navbar->add($title);
$PAGE->set_pagelayout('report');

$cachevalue = debugging() ? -1 : (int)get_config('block_completion_progress', 'cachevalue');
$PAGE->requires->css('/blocks/completion_progress/css.php?v=' . $cachevalue);

// Check user is logged in and capable of accessing the Overview.
require_login($course, false);
//require_capability('block/completion_progress:overview', $blockcontext);
confirm_sesskey();

$output = $PAGE->get_renderer('block_completion_progress');

// Start page output.
echo $OUTPUT->header();
echo $OUTPUT->heading($title, 2);
echo $OUTPUT->container_start('block_completion_progress');

global $USER, $DB, $CFG;

    $duedate = new DateTime('now');
    $duedate->modify('21 day');
    $duedate = $duedate->getTimestamp();
    $duedate = userdate($duedate);

    $student = $DB->get_record('user', array('id' => $studentid));
    $course = $DB->get_record('course', array('id' => $courseid));
    $coursename = $course->fullname .' - '.$course->shortname;
    $link = new moodle_url($CFG->wwwroot.'/course/view.php?', array('id' => $courseid));
    $courselink = $OUTPUT->action_link($link, $coursename, null, ['class' => 'btn btn-sm btn-primary', 'target' => '_blank']);

    if ($email == 'teacher') {
            // send email to Trainers
            $teacher_roleid = $DB->get_record('role', array('shortname' => 'teacher'), 'id');
    
            $teachers = get_role_users($teacher_roleid->id, $context, false);
            foreach ($teachers as $touser){
                $message = 'Hi <b>'.$touser->firstname. ' '.$touser->lastname.'</b>
                            </br>
                            </br>
                            This is a notification that <b>'. $student->firstname.' '. $student->lastname .'('.$student->username.')</b> 
                            </br>
                            assessment submission for '.$courselink.' 
                            </br>
                            is ready and has been allocated to you for marking in Creatine 2.
                            </br>
                            Please ensure this assessment submission is marked before <b>NEXT 21 Days ('. $duedate.')
                            </b></br>
                            If you have any questions or concerns, please contact the College, ASAP. 
                            </br>
                            Do not reply to this email.
                            </br>
                            </br>     
                            Regards
                            </br>
                            <b>Australian College</b>';

                $emailstatus = email_to_user($touser, $USER, $subject, $message, '', '', '', false);
            }
    } 
    if ($email == 'student') {

        // Trainer email to student
        $touser = $DB->get_record('user', array('id' => $userto));
        $userfrom = $USER;
        $subject = 'Assessment result Notification';
        $message = 'Hi <b>'.$touser->firstname. ' '.$touser->lastname.'</b>
                    </br>
                    </br>
                    This is a notification that assessment submission for '.$courselink.'
                    </br>
                    is finished. check your grades '.$courselink.'
                    If your result is not satisfactory,
                    contact Australian college student administrator. 
                    </br>
                    </br>     
                    Regards
                    </br>
                    <b>Australian College</b>';

        $emailstatus = email_to_user($touser, $userfrom, $subject, $message, '', '', '', false);
    }

    $link = new moodle_url($CFG->wwwroot.'/blocks/completion_progress/overview.php', 
                    array('instanceid' => $instanceid, 'courseid' => $courseid, 'sesskey' => sesskey()));
    
    redirect(new moodle_url($link));

echo $OUTPUT->container_end();
echo $OUTPUT->footer();