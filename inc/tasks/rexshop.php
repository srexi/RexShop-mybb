<?php

function task_rexshop($task)
{
    global $db, $lang, $mybb;

    $lang->load("rexshop");

    // Get banned user groups
    $banned_groups = array();
    $q = $db->simple_select('usergroups', 'gid', 'isbannedgroup=1');
    while ($gid = $db->fetch_field($q, 'gid')) {
        $banned_groups[] = (int) $gid;
    }

    // get expired one-off subscriptions
    $query = $db->query("
		SELECT r.*, u.uid, u.usergroup as user_usergroup FROM `" . TABLE_PREFIX . "rexshop_logs` r 
		LEFT JOIN `" . TABLE_PREFIX . "users` u ON (u.uid=r.uid)
		WHERE r.enddate>0 AND r.enddate < " . time() . " AND `expired`='0'");

    while ($sub = $db->fetch_array($query)) {
        //send pm about expired subscription
        rexshop_send_pm(
            "Your Subscription has Expired!",
            $lang->sprintf("Your subscription has just expired.\nPlease renew your subscription by clicking here: [url=" . $mybb->settings['bburl'] . "/misc.php?action=store][u][color=#32CD32]Renew Subscription[/color][/u][/url]"),
            $sub['uid']
        );

        // Leave user group
        $db->update_query('users', [
            'usergroup' => REXSHOP_USERGROUP_EXPIRED
        ], 'uid=' . $sub['uid']);

        // Set subscription to expired
        $db->update_query('rexshop_logs', [
            'expired' => 1
        ], 'id=' . $sub['id']);
    }

    add_task_log($task, $lang->task_rexshop_ran);
}
