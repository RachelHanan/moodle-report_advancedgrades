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
 * @package    report_advancedgrades
 * @copyright  2014 Paul Nicholls
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

function report_advancedgrades_get_students($courseid) {
    global $DB;
    return $DB->get_records_sql('SELECT stu.id AS userid, stu.idnumber AS idnumber,
        stu.firstname, stu.lastname, stu.username,stu.email
        FROM {user} stu
        JOIN {user_enrolments} ue ON ue.userid = stu.id
        JOIN {enrol} enr ON ue.enrolid = enr.id
        WHERE enr.courseid = ?
        ORDER BY lastname ASC, firstname ASC, userid ASC', array($courseid));
}

function report_advancedgrades_add_header($workbook, $sheet, $coursename, $modname, $method, $methodname) {
    // Course, assignment, marking guide / rubric names /BTEC.
    $format = $workbook->add_format(array('size' => 18, 'bold' => 1));
    $sheet->write_string(0, 0, $coursename, $format);
    $sheet->set_row(0, 24, $format);
    $format = $workbook->add_format(array('size' => 16, 'bold' => 1));
    $sheet->write_string(1, 0, $modname, $format);
    $sheet->set_row(1, 21, $format);
    $sheet->write_string(2, 0, $methodname, $format);
    $sheet->set_row(2, 21, $format);

    // Column headers - two rows for grouping.
    /* to set the background color ,'bg_color'=>'#C0C0C0'*/
    $format = $workbook->add_format(array('size' => 12, 'bold' => 1));
    $format2 = $workbook->add_format(array('bold' => 1));

    $sheet->write_string(4, 0, get_string('student', 'report_advancedgrades'), $format);
    $sheet->merge_cells(4, 0, 4, 2, $format);
    $sheet->write_string(5, 0, get_string('firstname', 'report_advancedgrades'), $format2);
    $sheet->write_string(5, 1, get_string('lastname', 'report_advancedgrades'), $format2);
    $sheet->write_string(5, 2, get_string('username', 'report_advancedgrades'), $format2);

    $pos=3;
    $config = get_config('report_advancedgrades');
    if($config->includestudentid==1){      
        $sheet->write_string(5, $pos++, get_string('studentid', 'report_advancedgrades'), $format2);
    }
    if($config->includeemail==1){
        $sheet->write_string(5, $pos, get_string('includeemail', 'report_advancedgrades'), $format2);
        $sheet->set_column(0, $pos++,20);
    }
    
    $sheet->set_column(0, 3, 10); // Set column widths to 10.
    return $pos;
}

function report_advancedgrades_finish_colheaders($workbook, $sheet, $pos) {
    // Grading info columns.
    $format = $workbook->add_format(array('size' => 12, 'bold' => 1));
    $format2 = $workbook->add_format(array('bold' => 1));
    $sheet->write_string(4, $pos, get_string('gradinginfo', 'report_advancedgrades'), $format);
    $sheet->write_string(5, $pos, get_string('gradedby', 'report_advancedgrades'), $format2);
    $sheet->set_column($pos, $pos++, 10); // Set column width to 10.
    $sheet->write_string(5, $pos, get_string('timegraded', 'report_advancedgrades'), $format2);
    $sheet->set_column($pos, $pos, 17.5); // Set column width to 17.5.
    $sheet->merge_cells(4, $pos - 1, 4, $pos);

    $sheet->set_row(4, 15, $format);
    $sheet->set_row(5, null, $format2);

    // Merge header cells.
    $sheet->merge_cells(0, 0, 0, $pos);
    $sheet->merge_cells(1, 0, 1, $pos);
    $sheet->merge_cells(2, 0, 2, $pos);
}

function report_advancedgrades_process_data($students, $data) {
    foreach ($students as $student) {
        $student->data = array();
        foreach ($data as $key => $line) {
            if ($line->userid == $student->userid) {
                $student->data[$key] = $line;
                unset($data[$key]);
            }
        }
    }
    return $students;
}

function report_advancedgrades_add_data($sheet, $students, $gradinginfopos, $method,$datacol) {
    // Actual data.
    $lastuser = 0;
    $row = 5;
    foreach ($students as $student) {
        $col=0;
        $row++;
        $sheet->write_string($row, $col++, $student->firstname);
        $sheet->write_string($row, $col++, $student->lastname);
        $sheet->write_string($row, $col++, $student->username);
        $config = get_config('report_advancedgrades');
        if($config->includestudentid){
            $sheet->write_string($row, $col++, $student->idnumber);
        }
        if($config->includeemail){
            $sheet->write_string($row, $col++, $student->email);
        }
        
        $pos = $datacol;
        foreach ($student->data as $line) {
            if (is_numeric($line->score)) {
                $sheet->write_number($row, $pos++, $line->score);
            } else {
                $sheet->write_string($row, $pos++, $line->score);
            }
            if ($method == 'rubric') {
                // Only rubrics have a "definition".
                $sheet->write_string($row, $pos++, $line->definition);
            }
            $sheet->write_string($row, $pos++, $line->remark);

            if ($pos === $gradinginfopos) {
                if ($method == 'btec') {
                    /* Add the overall assignment grade converted to R,P,M,D
                     * and the feedback given for the overal assignment
                     */
                    $sheet->set_column($pos, $pos, 12);
                    $sheet->write_string($row, $pos++, $line->grade);
                    /*add the per-criteria feedback */
                    $sheet->set_column($pos, $pos, 50);
                    $sheet->write_string($row, $pos++, $line->commenttext);
                }
                $sheet->set_column($pos, $pos, 15);
                $sheet->write_string($row, $pos++, $line->grader);
                $sheet->set_column($pos, $pos, 35);
                $sheet->write_string($row, $pos, userdate($line->modified));
            }
        }
    }
}
