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
 * This file contains the course_enrolment_manager class which is used to interface
 * with the functions that exist in enrollib.php in relation to a single course.
 *
 * @package   moodlecore
 * @copyright 2010 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This class provides a targeted tied together means of interfacing the enrolment
 * tasks together with a course.
 *
 * It is provided as a convenience more than anything else.
 *
 * @copyright 2010 Sam Hemelryk
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_enrolment_manager {

    /**
     * The course context
     * @var stdClass
     */
    protected $context;
    /**
     * The course we are managing enrolments for
     * @var stdClass
     */
    protected $course = null;
    /**
     * Limits the focus of the manager to one enrolment plugin instance
     * @var string
     */
    protected $instancefilter = null;

    /**
     * The total number of users enrolled in the course
     * Populated by course_enrolment_manager::get_total_users
     * @var int
     */
    protected $totalusers = null;
    /**
     * An array of users currently enrolled in the course
     * Populated by course_enrolment_manager::get_users
     * @var array
     */
    protected $users = array();

    /**#@+
     * These variables are used to cache the information this class uses
     * please never use these directly instead use thier get_ counterparts.
     * @access private
     * @var array
     */
    private $_instancessql = null;
    private $_instances = null;
    private $_inames = null;
    private $_plugins = null;
    private $_roles = null;
    private $_assignableroles = null;
    private $_groups = null;
    /**#@-*/

    /**
     * Constructs the course enrolment manager
     *
     * @param stdClass $course
     * @param string $instancefilter
     */
    public function __construct($course, $instancefilter = null) {
        $this->context = get_context_instance(CONTEXT_COURSE, $course->id);
        $this->course = $course;
        $this->instancefilter = $instancefilter;
    }

    /**
     * Returns the total number of enrolled users in the course.
     *
     * If a filter was specificed this will be the total number of users enrolled
     * in this course by means of that instance.
     *
     * @global moodle_database $DB
     * @return int
     */
    public function get_total_users() {
        global $DB;
        if ($this->totalusers === null) {
            list($instancessql, $params, $filter) = $this->get_instance_sql();
            $sqltotal = "SELECT COUNT(DISTINCT u.id)
                           FROM {user} u
                           JOIN {user_enrolments} ue ON (ue.userid = u.id  AND ue.enrolid $instancessql)
                           JOIN {enrol} e ON (e.id = ue.enrolid)";
            $this->totalusers = (int)$DB->count_records_sql($sqltotal, $params);
        }
        return $this->totalusers;
    }

    /**
     * Gets all of the users enrolled in this course.
     *
     * If a filter was specificed this will be the users who were enrolled
     * in this course by means of that instance.
     *
     * @global moodle_database $DB
     * @param string $sort
     * @param string $direction ASC or DESC
     * @param int $page First page should be 0
     * @param int $perpage Defaults to 25
     * @return array
     */
    public function get_users($sort, $direction='ASC', $page=0, $perpage=25) {
        global $DB;
        if ($direction !== 'ASC') {
            $direction = 'DESC';
        }
        $key = md5("$sort-$direction-$page-$perpage");
        if (!array_key_exists($key, $this->users)) {
            list($instancessql, $params, $filter) = $this->get_instance_sql();
            $sql = "SELECT DISTINCT u.*, ul.timeaccess AS lastseen
                      FROM {user} u
                      JOIN {user_enrolments} ue ON (ue.userid = u.id  AND ue.enrolid $instancessql)
                      JOIN {enrol} e ON (e.id = ue.enrolid)
                 LEFT JOIN {user_lastaccess} ul ON (ul.courseid = e.courseid AND ul.userid = u.id)";
            if ($sort === 'firstname') {
                $sql .= " ORDER BY u.firstname $direction, u.lastname $direction";
            } else if ($sort === 'lastname') {
                $sql .= " ORDER BY u.lastname $direction, u.firstname $direction";
            } else if ($sort === 'email') {
                $sql .= " ORDER BY u.email $direction, u.lastname $direction, u.firstname $direction";
            } else if ($sort === 'lastseen') {
                $sql .= " ORDER BY ul.timeaccess $direction, u.lastname $direction, u.firstname $direction";
            }
            $this->users[$key] = $DB->get_records_sql($sql, $params, $page*$perpage, $perpage);
        }
        return $this->users[$key];
    }

    /**
     * Gets an array of the users that can be enrolled in this course.
     *
     * @global moodle_database $DB
     * @param int $enrolid
     * @param string $search
     * @param bool $searchanywhere
     * @param int $page Defaults to 0
     * @param int $perpage Defaults to 25
     * @return array Array(totalusers => int, users => array)
     */
    public function get_potential_users($enrolid, $search='', $searchanywhere=false, $page=0, $perpage=25) {
        global $DB;

        // Add some additional sensible conditions
        $tests = array("u.username <> 'guest'", 'u.deleted = 0', 'u.confirmed = 1');
        $params = array();
        if (!empty($search)) {
            $conditions = array('u.firstname','u.lastname');
            $ilike = ' ' . $DB->sql_ilike();
            if ($searchanywhere) {
                $searchparam = '%' . $search . '%';
            } else {
                $searchparam = $search . '%';
            }
            $i = 0;
            foreach ($conditions as &$condition) {
                $condition .= "$ilike :con{$i}00";
                $params["con{$i}00"] = $searchparam;
                $i++;
            }
            $tests[] = '(' . implode(' OR ', $conditions) . ')';
        }
        $wherecondition = implode(' AND ', $tests);

        $ufields = user_picture::fields('u');

        $fields      = 'SELECT u.id, u.firstname, u.lastname, u.username, u.email, u.lastaccess, u.picture, u.imagealt, '.$ufields;
        $countfields = 'SELECT COUNT(1)';
        $sql = " FROM {user} u
                WHERE $wherecondition
                      AND u.id NOT IN (SELECT ue.userid
                                         FROM {user_enrolments} ue
                                         JOIN {enrol} e ON (e.id = ue.enrolid AND e.id = :enrolid))";
        $order = ' ORDER BY u.lastname ASC, u.firstname ASC';
        $params['enrolid'] = $enrolid;
        $totalusers = $DB->count_records_sql($countfields . $sql, $params);
        $availableusers = $DB->get_records_sql($fields . $sql . $order, $params, $page*$perpage, $perpage);
        return array('totalusers'=>$totalusers, 'users'=>$availableusers);
    }

    /**
     * Gets an array containing some SQL to user for when selecting, params for
     * that SQL, and the filter that was used in constructing the sql.
     *
     * @global moodle_database $DB
     * @return string
     */
    protected function get_instance_sql() {
        global $DB;
        if ($this->_instancessql === null) {
            $instances = $this->get_enrolment_instances();
            $filter = $this->get_enrolment_filter();
            if ($filter && array_key_exists($filter, $instances)) {
                $sql = " = :ifilter";
                $params = array('ifilter'=>$filter);
            } else {
                $filter = 0;
                if ($instances) {
                    list($sql, $params) = $DB->get_in_or_equal(array_keys($this->get_enrolment_instances()), SQL_PARAMS_NAMED);
                } else {
                    // no enabled instances, oops, we should probably say something
                    $sql = "= :never";
                    $params = array('never'=>-1);
                }
            }
            $this->instancefilter = $filter;
            $this->_instancessql = array($sql, $params, $filter);
        }
        return $this->_instancessql;
    }

    /**
     * Returns all of the enrolment instances for this course.
     *
     * @return array
     */
    public function get_enrolment_instances() {
        if ($this->_instances === null) {
            $this->_instances = enrol_get_instances($this->course->id, true);
        }
        return $this->_instances;
    }

    /**
     * Returns the names for all of the enrolment instances for this course.
     *
     * @return array
     */
    public function get_enrolment_instance_names() {
        if ($this->_inames === null) {
            $instances = $this->get_enrolment_instances();
            $plugins = $this->get_enrolment_plugins();
            foreach ($instances as $key=>$instance) {
                if (!isset($plugins[$instance->enrol])) {
                    // weird, some broken stuff in plugin
                    unset($instances[$key]);
                    continue;
                }
                $this->_inames[$key] = $plugins[$instance->enrol]->get_instance_name($instance);
            }
        }
        return $this->_inames;
    }

    /**
     * Gets all of the enrolment plugins that are active for this course.
     *
     * @return array
     */
    public function get_enrolment_plugins() {
        if ($this->_plugins === null) {
            $this->_plugins = enrol_get_plugins(true);
        }
        return $this->_plugins;
    }

    /**
     * Gets all of the roles this course can contain.
     *
     * @return array
     */
    public function get_all_roles() {
        if ($this->_roles === null) {
            $this->_roles = role_fix_names(get_all_roles(), $this->context);
        }
        return $this->_roles;
    }

    /**
     * Gets all of the assignable roles for this course.
     *
     * @return array
     */
    public function get_assignable_roles() {
        if ($this->_assignableroles === null) {
            $this->_assignableroles = get_assignable_roles($this->context, ROLENAME_ALIAS, false); // verifies unassign access control too
        }
        return $this->_assignableroles;
    }

    /**
     * Gets all of the groups for this course.
     *
     * @return array
     */
    public function get_all_groups() {
        if ($this->_groups === null) {
            $this->_groups = groups_get_all_groups($this->course->id);
            foreach ($this->_groups as $gid=>$group) {
                $this->_groups[$gid]->name = format_string($group->name);
            }
        }
        return $this->_groups;
    }

    /**
     * Unenroles a user from the course given the users ue entry
     *
     * @global moodle_database $DB
     * @param stdClass $ue
     * @return bool
     */
    public function unenrol_user($ue) {
        global $DB;

        $instances = $this->get_enrolment_instances();
        $plugins = $this->get_enrolment_plugins();

        $user = $DB->get_record('user', array('id'=>$ue->userid), '*', MUST_EXIST);

        if (!isset($instances[$ue->enrolid])) {
            return false;
        }
        $instance = $instances[$ue->enrolid];
        $plugin = $plugins[$instance->enrol];
        if (!$plugin->allow_unenrol($instance) || !has_capability("enrol/$instance->enrol:unenrol", $this->context)) {
            return false;
        }
        $plugin->unenrol_user($instance, $ue->userid);
        return true;
    }

    /**
     * Removes an assigned role from a user.
     *
     * @global moodle_database $DB
     * @param int $userid
     * @param int $roleid
     * @return bool
     */
    public function unassign_role_from_user($userid, $roleid) {
        global $DB;
        $user = $DB->get_record('user', array('id'=>$userid), '*', MUST_EXIST);
        try {
            role_unassign($roleid, $user->id, $this->context->id, '', NULL);
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    /**
     * Assigns a role to a user.
     *
     * @param int $roleid
     * @param int $userid
     * @return int|false
     */
    public function assign_role_to_user($roleid, $userid) {
        require_capability('moodle/role:assign', $this->context);
        return role_assign($roleid, $userid, $this->context->id, '', NULL);
    }

    /**
     * Adds a user to a group
     *
     * @param stdClass $user
     * @param int $groupid
     * @return bool
     */
    public function add_user_to_group($user, $groupid) {
        require_capability('moodle/course:managegroups', $this->context);
        $group = $this->get_group($groupid);
        if (!$group) {
            return false;
        }
        return groups_add_member($group->id, $user->id);
    }

    /**
     * Removes a user from a group
     *
     * @global moodle_database $DB
     * @param StdClass $user
     * @param int $groupid
     * @return bool
     */
    public function remove_user_from_group($user, $groupid) {
        global $DB;
        require_capability('moodle/course:managegroups', $this->context);
        $group = $this->get_group($groupid);
        if (!$group) {
            return false;
        }
        return groups_remove_member($group, $user);
    }

    /**
     * Gets the requested group
     *
     * @param int $groupid
     * @return stdClass|int
     */
    public function get_group($groupid) {
        $groups = $this->get_all_groups();
        if (!array_key_exists($groupid, $groups)) {
            return false;
        }
        return $groups[$groupid];
    }

    /**
     * Edits an enrolment
     *
     * @param stdClass $userenrolment
     * @param stdClass $data
     * @return bool
     */
    public function edit_enrolment($userenrolment, $data) {
        $instances = $this->get_enrolment_instances();
        if (!array_key_exists($userenrolment->enrolid, $instances)) {
            return false;
        }
        $instance = $instances[$userenrolment->enrolid];

        $plugins = $this->get_enrolment_plugins();
        $plugin = $plugins[$instance->enrol];
        if (!$plugin->allow_unenrol($instance) || !has_capability("enrol/$instance->enrol:unenrol", $this->context)) {
            return false;
        }

        if (!isset($data->status)) {
            $data->status = $userenrolment->status;
        }

        $plugin->update_user_enrol($instance, $userenrolment->userid, $data->status, $data->timestart, $data->timeend);
        return true;
    }

    /**
     * Returns the current enrolment filter that is being applied by this class
     * @return string
     */
    public function get_enrolment_filter() {
        return $this->instancefilter;
    }

    /**
     * Gets the roles assigned to this user that are applicable for this course.
     *
     * @param int $userid
     * @return array
     */
    public function get_user_roles($userid) {
        $roles = array();
        $ras = get_user_roles($this->context, $userid, true, 'c.contextlevel DESC, r.sortorder ASC');
        foreach ($ras as $ra) {
            if ($ra->contextid != $this->context->id) {
                if (!array_key_exists($ra->roleid, $roles)) {
                    $roles[$ra->roleid] = null;
                }
                // higher ras, course always takes precedence
                continue;
            }
            if (array_key_exists($ra->roleid, $roles) && $roles[$ra->roleid] === false) {
                continue;
            }
            $roles[$ra->roleid] = ($ra->itemid == 0 and $ra->component === '');
        }
        return $roles;
    }

    /**
     * Gets the enrolments this user has in the course
     *
     * @global moodle_database $DB
     * @param int $userid
     * @return array
     */
    public function get_user_enrolments($userid) {
        global $DB;
        list($instancessql, $params, $filter) = $this->get_instance_sql();
        $params['userid'] = $userid;
        $userenrolments = $DB->get_records_select('user_enrolments', "enrolid $instancessql AND userid = :userid", $params);
        $instances = $this->get_enrolment_instances();
        $plugins = $this->get_enrolment_plugins();
        $inames = $this->get_enrolment_instance_names();
        foreach ($userenrolments as &$ue) {
            $ue->enrolmentinstance     = $instances[$ue->enrolid];
            $ue->enrolmentplugin       = $plugins[$ue->enrolmentinstance->enrol];
            $ue->enrolmentinstancename = $inames[$ue->enrolmentinstance->id];
        }
        return $userenrolments;
    }

    /**
     * Gets the groups this user belongs to
     *
     * @param int $userid
     * @return array
     */
    public function get_user_groups($userid) {
        return groups_get_all_groups($this->course->id, $userid, 0, 'g.id');
    }

    /**
     * Retursn an array of params that would go into the URL to return to this
     * exact page.
     *
     * @return array
     */
    public function get_url_params() {
        $args = array(
            'id' => $this->course->id
        );
        if (!empty($this->instancefilter)) {
            $args['ifilter'] = $this->instancefilter;
        }
        return $args;
    }

    /**
     * Returns the course this object is managing enrolments for
     *
     * @return stdClass
     */
    public function get_course() {
        return $this->course;
    }

    /**
     * Returns the course context
     *
     * @return stdClass
     */
    public function get_context() {
        return $this->context;
    }

    /**
     * Gets an array of users for display, this includes minimal user information
     * as well as minimal information on the users roles, groups, and enrolments.
     *
     * @param core_enrol_renderer $renderer
     * @param moodle_url $pageurl
     * @param int $sort
     * @param string $direction ASC or DESC
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public function get_users_for_display(core_enrol_renderer $renderer, moodle_url $pageurl, $sort, $direction, $page, $perpage) {
        $users = $this->get_users($sort, $direction, $page, $perpage);

        $now = time();
        $strnever = get_string('never');
        $straddgroup = get_string('addgroup', 'group');
        $strunenrol = get_string('unenrol', 'enrol');
        $stredit = get_string('edit');

        $iconedit        = $renderer->pix_url('t/edit');
        $iconenroladd    = $renderer->pix_url('t/enroladd');
        $iconenrolremove = $renderer->pix_url('t/delete');

        $allroles   = $this->get_all_roles();
        $assignable = $this->get_assignable_roles();
        $allgroups  = $this->get_all_groups();
        $courseid   = $this->get_course()->id;
        $context    = $this->get_context();
        $canmanagegroups = has_capability('moodle/course:managegroups', $context);

        $url = new moodle_url($pageurl, $this->get_url_params());

        $userdetails = array();
        foreach ($users as $user) {
            $details = array(
                'userid'     => $user->id,
                'courseid'   => $courseid,
                'picture'    => new user_picture($user),
                'firstname'  => fullname($user, true),
                'email'      => $user->email,
                'lastseen'   => $strnever,
                'roles'      => array(),
                'groups'     => array(),
                'enrolments' => array()
            );

            if ($user->lastseen) {
                $details['lastseen'] = format_time($user->lastaccess);
            }

            // Roles
            foreach ($this->get_user_roles($user->id) as $rid=>$rassignable) {
                $details['roles'][$rid] = array('text'=>$allroles[$rid]->localname, 'unchangeable'=>(!$rassignable || !isset($assignable[$rid])));
            }

            // Users
            $usergroups = $this->get_user_groups($user->id);
            foreach($usergroups as $gid=>$unused) {
                $details['groups'][$gid] = $allgroups[$gid]->name;
            }

            // Enrolments
            foreach ($this->get_user_enrolments($user->id) as $ue) {
                if ($ue->timestart and $ue->timeend) {
                    $period = get_string('periodstartend', 'enrol', array('start'=>userdate($ue->timestart), 'end'=>userdate($ue->timeend)));
                    $periodoutside = ($ue->timestart && $ue->timeend && $now < $ue->timestart && $now > $ue->timeend);
                } else if ($ue->timestart) {
                    $period = get_string('periodstart', 'enrol', userdate($ue->timestart));
                    $periodoutside = ($ue->timestart && $now < $ue->timestart);
                } else if ($ue->timeend) {
                    $period = get_string('periodend', 'enrol', userdate($ue->timeend));
                    $periodoutside = ($ue->timeend && $now > $ue->timeend);
                } else {
                    $period = '';
                    $periodoutside = false;
                }
                $details['enrolments'][$ue->id] = array(
                    'text' => $ue->enrolmentinstancename,
                    'period' => $period,
                    'dimmed' =>  ($periodoutside || $ue->status != ENROL_USER_ACTIVE),
                    'canunenrol' => ($ue->enrolmentplugin->allow_unenrol($ue->enrolmentinstance) && has_capability("enrol/".$ue->enrolmentinstance->enrol.":unenrol", $context)),
                    'canmanage' => ($ue->enrolmentplugin->allow_manage($ue->enrolmentinstance) && has_capability("enrol/".$ue->enrolmentinstance->enrol.":manage", $context))
                );
            }
            $userdetails[$user->id] = $details;
        }
        return $userdetails;

        if (1==2){
            // get list of roles
            $roles = $this->get_user_roles($user->id);
            foreach ($roles as $rid=>$unassignable) {
                if ($unassignable && isset($assignable[$rid])) {
                    $icon = html_writer::empty_tag('img', array('alt'=>get_string('unassignarole', 'role', $allroles[$rid]->localname), 'src'=>$iconenrolremove));
                    $url = new moodle_url($url, array('action'=>'unassign', 'role'=>$rid, 'user'=>$user->id));
                    $roles[$rid] = html_writer::tag('div', $allroles[$rid]->localname . html_writer::link($url, $icon, array('class'=>'unassignrolelink', 'rel'=>$rid)), array('class'=>'role role_'.$rid));
                } else {
                    $roles[$rid] = html_writer::tag('div', $allroles[$rid]->localname, array('class'=>'role unchangeable', 'rel'=>$rid));
                }
            }
            $addrole = '';
            if ($assignable) {
                foreach ($assignable as $rid=>$unused) {
                    if (!isset($roles[$rid])) {
                        //candidate for role assignment
                        $url = new moodle_url($url, array('action'=>'assign', 'user'=>$user->id));
                        $icon = html_writer::empty_tag('img', array('alt'=>get_string('assignroles', 'role', $allroles[$rid]->localname), 'src'=>$iconenroladd));
                        $addrole .= html_writer::link($url, $icon, array('class'=>'assignrolelink'));
                        break;
                    }
                }
            }
            $roles = html_writer::tag('div', implode('', $roles), array('class'=>'roles'));
            if ($addrole) {
                $roles = html_writer::tag('div', $addrole, array('class'=>'addrole')).$roles;
            }

            // Get list of groups
            $usergroups = $this->get_user_groups($user->id);
            $groups = array();
            foreach($usergroups as $gid=>$unused) {
                $group = $allgroups[$gid];
                if ($canmanagegroups) {
                    $icon = html_writer::empty_tag('img', array('alt'=>get_string('removefromgroup', 'group', $group->name), 'src'=>$iconenrolremove));
                    $url = new moodle_url($url, array('action'=>'removemember', 'group'=>$gid, 'user'=>$user->id));
                    $groups[] = $group->name . html_writer::link($url, $icon);
                } else {
                    $groups[] = $group->name;
                }
            }
            $groups = implode(', ', $groups);
            if ($canmanagegroups and (count($usergroups) < count($allgroups))) {
                $icon = html_writer::empty_tag('img', array('alt'=>$straddgroup, 'src'=>$iconenroladd));
                $url = new moodle_url($url, array('action'=>'addmember', 'user'=>$user->id));
                $groups .= '<div>'.html_writer::link($url, $icon).'</div>';
            }


            // get list of enrol instances
            $ues = $this->get_user_enrolments($user->id);
            $edits = array();
            foreach ($ues as $ue) {
                $edit       = $ue->enrolmentinstancename;

                $dimmed = false;
                if ($ue->timestart and $ue->timeend) {
                    $edit .= '&nbsp;('.get_string('periodstartend', 'enrol', array('start'=>userdate($ue->timestart), 'end'=>userdate($ue->timeend))).')';
                    $dimmed = ($now < $ue->timestart and $now > $ue->timeend);
                } else if ($ue->timestart) {
                    $edit .= '&nbsp;('.get_string('periodstart', 'enrol', userdate($ue->timestart)).')';
                    $dimmed = ($now < $ue->timestart);
                } else if ($ue->timeend) {
                    $edit .= '&nbsp;('.get_string('periodend', 'enrol', userdate($ue->timeend)).')';
                    $dimmed = ($now > $ue->timeend);
                }

                if ($dimmed or $ue->status != ENROL_USER_ACTIVE) {
                    $edit = html_writer::tag('span', $edit, array('class'=>'dimmed_text'));
                }

                if ($ue->enrolmentplugin->allow_unenrol($ue->enrolmentinstance) && has_capability("enrol/".$ue->enrolmentinstance->enrol.":unenrol", $context)) {
                    $icon = html_writer::empty_tag('img', array('alt'=>$strunenrol, 'src'=>$iconenrolremove));
                    $url = new moodle_url($url, array('action'=>'unenrol', 'ue'=>$ue->id));
                    $edit .= html_writer::link($url, $icon, array('class'=>'unenrollink', 'rel'=>$ue->id));
                }

                if ($ue->enrolmentplugin->allow_manage($ue->enrolmentinstance) && has_capability("enrol/".$ue->enrolmentinstance->enrol.":manage", $context)) {
                    $icon = html_writer::empty_tag('img', array('alt'=>$stredit, 'src'=>$iconedit));
                    $url = new moodle_url($url, array('action'=>'edit', 'ue'=>$ue->id));
                    $edit .= html_writer::link($url, $icon, array('class'=>'editenrollink', 'rel'=>$ue->id));
                }

                $edits[] = html_writer::tag('div', $edit, array('class'=>'enrolment'));
            }
            $edits = implode('', $edits);


            $userdetails[$user->id] = array(
                'picture'   => $renderer->user_picture($user, array('courseid'=>$courseid)),
                'firstname' => html_writer::link(new moodle_url('/user/view.php', array('id'=>$user->id, 'course'=>$courseid)), fullname($user, true)),
                'email'     => $user->email,
                'lastseen'  => $strlastaccess,
                'role'      => $roles,
                'group'     => $groups,
                'enrol'     => $edits
            );
        }
        return $userdetails;
    }
}