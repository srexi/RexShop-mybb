<?php

if (!defined("IN_MYBB")) {
    die("This file cannot be accessed directly.");
}

define('TIME_NOW', time());
define('BAN_REASON_CHARGEBACK', 'payment_disputed');
define('REXSHOP_USERGROUP_EXPIRED', 2);
define('REXSHOP_STATUS_COMPLETED', 'completed');
define('REXSHOP_STATUS_PENDING', 'waiting for payment');
define('REXSHOP_STATUS_REFUNDED', 'refunded');
define('REXSHOP_STATUS_DISPUTED', 'disputed');
define('REXSHOP_STATUS_DISPUTE_CANCELED', 'dispute canceled');
define('REXSHOP_STATUS_REVERSED', 'reversed');

define('SECONDS_PER_DAY', 86400);

$plugins->add_hook('admin_load', 'rexshop_admin');
$plugins->add_hook('admin_user_menu', 'rexshop_admin_user_menu');
$plugins->add_hook('admin_user_action_handler', 'rexshop_admin_user_action_handler');
$plugins->add_hook('admin_user_permissions', 'rexshop_admin_permissions');

$plugins->add_hook('usercp_menu', 'rexshop_usercp_menu');
$plugins->add_hook("misc_start", "rexshop_payment_page");
$plugins->add_hook("misc_start", "rexshop_webhook_handler");

function rexshop_info()
{
    return array(
        "name"            => "RexShop",
        "description"    => "Admins can setup Rex Shop for their website.",
        "website"        => "https://shop.rexdigital.group",
        "author"        => "RexDigitalGroup",
        "authorsite"    => "https://rexdigital.group",
        "version"        => "1.05",
        "guid"             => "",
        "compatibility"    => "18*,16*"
    );
}

function rexshop_install()
{
    global $db;

    //Settings
    $gid = $db->insert_query("settinggroups", [
        'name' => 'RexShop',
        'title' => 'RexShop Setting',
        'description' => "Settings for RexShop plugin",
        'disporder' => 100,
        'isdefault' => 0
    ]);
    $db->insert_query("settings", [
        "name" => "rexshop_client_id",
        "title" => "RexShop Client Id",
        "description" => "Type your RexShop Client Id.",
        "optionscode" => 'text',
        "value" => '',
        "disporder" => 0,
        "gid" => intval($gid),
    ]);
    $db->insert_query("settings", [
        "name" => "rexshop_secret",
        "title" => "RexShop Secret",
        "description" => "Type your RexShop Secret.",
        "optionscode" => 'text',
        "value" => '',
        "disporder" => 1,
        "gid" => intval($gid),
    ]);
    $db->insert_query("settings", [
        "name" => "rexshop_api_key",
        "title" => "RexShop Api Key",
        "description" => "Type your RexShop API Key.",
        "optionscode" => 'text',
        "value" => '',
        "disporder" => 2,
        "gid" => intval($gid),
    ]);
    $db->insert_query("settings", [
        "name" => "rexshop_exclude_usergroups",
        "title" => "Exclude Usergroups",
        "description" => "A comma seperated list of usergroups NOT allowed to buy your product. (Example: 2,3,8,10)",
        "optionscode" => 'text',
        "value" => '',
        "disporder" => 2,
        "gid" => intval($gid),
    ]);
    rebuild_settings();

    //Tables
    $collation = $db->build_create_table_collation();
    $db->write_query("CREATE TABLE `" . TABLE_PREFIX . "rexshop_logs` (
        `id` bigint(30) UNSIGNED NOT NULL auto_increment,
        `transaction_id` varchar(192),
        `product_sku` varchar(192),
        `country` varchar(192),
        `uid` int(11) NOT NULL,
        `transaction_status` varchar(192) NOT NULL,
        `suspended_seconds` int(11) default '0',
        `enddate` int(12) NOT NULL,
        `expired` tinyint(1) default '0',
        `transaction_from` int(12),
        PRIMARY KEY  (`id`)
    ) ENGINE=MyISAM{$collation}");

    //Tasks
    $db->insert_query("tasks", [
        "title" => "RexShop",
        "description" => "Checks for members whose subscriptions have expired.",
        "file" => "rexshop",
        "minute" => '*',
        "hour" => '*',
        "day" => '*',
        "month" => '*',
        "weekday" => '*',
        "enabled" => '1',
        "logging" => '1',
        'nextrun' => 0,
    ]);
}

function rexshop_uninstall()
{
    global $db;

    //Templates
    //Delete settings group
    $db->delete_query("settinggroups", "name = 'RexShop'");
    //Remove settings
    $db->delete_query('settings', 'name LIKE \'%rexshop%\'');

    rebuild_settings();

    if ($db->table_exists('rexshop_logs')) {
        $db->drop_table('rexshop_logs');
    }

    $db->delete_query('tasks', 'file=\'rexshop\'');
}

function rexshop_activate()
{
    global $db;

    //Insert templates
    $db->insert_query("templates", [
        "title" => 'rexshop',
        "template" => $db->escape_string('
            <html>
                <head>
                    <title>{$lang->rexshop}</title>
                    {$headerinclude}
                </head>
                <body>
                    {$header}
                    <table border="0" cellspacing="{$theme[\'borderwidth\']}" cellpadding="{$theme[\'tablespace\']}" class="tborder">
                        <tr>
                            <td class="thead" colspan="5"><strong>{$lang->rexshop_plans}</strong></td>
                        </tr>
                        <tr>
                            <td class="tcat" width="40%"><strong>{$lang->rexshop_title}</strong></td>
                            <td class="tcat" width="15%" align="center"><strong>{$lang->rexshop_usergroup}</strong></td>
                            <td class="tcat" width="15%" align="center"><strong>{$lang->rexshop_period}</strong></td>
                            <td class="tcat" width="15%" align="center"><strong>{$lang->rexshop_price}</strong></td>
                            <td class="tcat" width="15%" align="center"><strong>{$lang->rexshop_subscribe}</strong></td>
                        </tr>
                        {$subplans}
                    </table>
                    {$footer}
                </body>
            </html>'),
        "sid" => "-1",
    ]);
}

function rexshop_deactivate()
{
    global $db;
    $db->delete_query('templates', 'title IN (\'rexshop\')');
}

function rexshop_is_installed()
{
    global $db;
    return (bool) $db->table_exists('rexshop_logs');
}

function rexshop_usercp_menu()
{
    global $mybb, $usercpmenu;

    $usercpmenu .= '
        <tr>
            <td class="trow1 smalltext" style="display:flex;">
                <img src="https://shop.rexdigital.group/img/brand/logo.svg" width="17" height="17">
                <a href="' . $mybb->settings['bburl'] . '/misc.php?action=store" class="usercp_nav_item" style="background-image: none;padding-left: 7px;">Store</a>
            </td>
        </tr>';
}

function rexshop_payment_page()
{
    global $mybb, $db, $header, $headerinclude, $footer, $theme, $lang;

    $lang->load('rexshop');

    if ($mybb->input['action'] == 'store') {
        if (!$mybb->user['uid'] || !rexshop_allowed_to_buy($mybb->user['usergroup'])) {
            error_no_permission();
        }
        if (!isset($mybb->settings['rexshop_client_id'])) {
            error("The admin has not setup the payment system fully, yet. ERROR: missing client id");
        }
        if (!isset($mybb->settings['rexshop_api_key'])) {
            error("The admin has not setup the payment system fully, yet. ERROR: mising api key");
        }
        if (!isset($mybb->settings['rexshop_secret'])) {
            error("The admin has not setup the payment system fully, yet. ERROR: mising secret");
        }

        add_breadcrumb($lang->rexshop, "misc.php?action=store");

        $contents = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
            <html xml:lang="en" lang="en" xmlns="http://www.w3.org/1999/xhtml">
                <head>
                    <title>' . $mybb->settings['bbname'] . ' - Store</title>
                    ' . $headerinclude . '
                </head>
            <body>' . $header;

        if ($mybb->input['plan_id'] && $mybb->input['my_post_key'] == $mybb->post_code) {
            verify_post_check($mybb->input['my_post_key']);

            $products = rexshop_fetch_products();

            $selectedProduct = null;
            foreach ($products as $product) {
                if (!isset($mybb->input[$product['sku']])) {
                    continue;
                }

                foreach ($product['prices'] as $price) {
                    if ($price['plan_id'] != $mybb->input['plan_id'][$product['sku']]) {
                        continue;
                    }

                    $product['prices'] = array_values(array_filter($product['prices'], function ($price) use ($mybb, $product) {
                        return $price['plan_id'] == $mybb->input['plan_id'][$product['sku']];
                    }));

                    $selectedProduct = $product;
                    break;
                }
            }

            if (!isset($selectedProduct) || empty($selectedProduct['prices'])) {
                redirect('misc.php?action=store', $lang->error_invalid_subscription);
            }

            add_breadcrumb("{$selectedProduct['name']} - {$selectedProduct['prices'][0]['name']} ({$selectedProduct['prices'][0]['time']} {$selectedProduct['prices'][0]['duration']}) - {$selectedProduct['prices'][0]['currency']}{$selectedProduct['prices'][0]['price']}", "misc.php?action=store");

            $custom = base64_encode(serialize([
                'uid' => (int) $mybb->user['uid'],
            ]));

            $contents .= "
                <form method='POST' action='https://shop.rexdigital.group/checkout'>
                    <input name='client_id' value='" . rexshop_regex_escape($mybb->settings['rexshop_client_id'], '/[^a-zA-Z0-9]/') . "' type='hidden'>
                    <input name='products[0][plan_id]' value='" . rexshop_regex_escape($selectedProduct['prices'][0]['plan_id'], '/[^a-zA-Z0-9]/') . "' type='hidden'>
                    <input name='custom' value='${custom}' type='hidden'>
                    <button class='button' type='submit'>Go to checkout</button>
                </form>";
        } else {
            $products = rexshop_fetch_products();

            $payments = '';
            foreach ($products as $product) {
                $options = '';
                foreach ($product['prices'] as $price) {
                    $options .= '<option value="' . $price['plan_id'] . '">' . $price['name'] . ' ' . $price['currency'] . $price['price'] . ' (' . $price['time'] . ' ' . $price['duration']  . ')</option>';
                }

                $payments .= '
                    <fieldset>
                        <legend><strong>' . $product['name'] . '</strong></legend>
                        <div class="smalltext" style="width: 65%;">
                            ' . $product['description'] . '
                        </div>
                        <div align="right" style="margin-right: 100px;">
                            <select style="width: 240px;" name="plan_id[' . $product["sku"] . ']">
                                ' . $options . '
                            </select> 
                            
                            <button type="submit" name="' . $product["sku"] . '" class="button">Order</button>
                        </div>
                    </fieldset>';
            }

            if (empty($payments)) {
                $payments = '<p style="text-align: center;">' . $lang->no_subscription_options . '</p>';
            }

            $enddate = (int) (TIME_NOW + rexshop_remaining_seconds(intval($mybb->user['uid']), false));

            if ($enddate > TIME_NOW) {
                $offsetquery = $db->simple_select("users", "timezone", "uid='" . intval($mybb->user['uid']) . "'");
                $offset = (int) $db->fetch_field($offsetquery, "timezone");
                $enddate += (3600 * $offset);

                $contents .= "Your subscription expires: " . date('j, M Y H:m', $enddate) . " GMT" . (strpos($offset, '-') !== false ? $offset : "+{$offset}") . "<br><br>";
            } else if ($enddate <= -1) {
                $contents .= "Your subscription will never expire";
            }

            $contents .= "
                <form action='misc.php?action=store' method='post'>
                    <input type='hidden' name='my_post_key' value='{$mybb->post_code}' />
                    <table border='0' cellspacing='{$theme['borderwidth']}' cellpadding='{$theme['tablespace']}' class='tborder'>
                        <tr>
                            <td class='thead' align='center'><strong>Store</strong></td>
                        </tr>
                        <tr>
                            <td class='trow1' valign='bottom'>
                                {$payments}
                            </td>
                        </tr>
                    </table>
                </form>";
        }
        $contents .= $footer . '
        </body>
        </html>';

        output_page($contents);
    }
}

/*************************************************************************************/
// WEBHOOKS
/*************************************************************************************/

function rexshop_webhook_handler()
{
    global $mybb;

    if (isset($mybb->input['payment']) && strtolower($mybb->input['payment']) === 'rexshop') {
        $request = json_decode(file_get_contents("php://input"), true);

        if (!rexshop_verify_webhook($request)) {
            error_no_permission();
        }

        if (rexshop_transaction_duplicate($request)) {
            return rexshop_on_success();
        }

        switch (strtolower($request['status'])) {
            case REXSHOP_STATUS_COMPLETED:
                return handleCompletedTransaction($request);
            case REXSHOP_STATUS_PENDING:
                return handlePendingTransaction($request);
            case REXSHOP_STATUS_REFUNDED:
                return handleRefundedTransaction($request);
            case REXSHOP_STATUS_DISPUTE_CANCELED:
                return handleDisputeCanceledTransaction($request);
            case REXSHOP_STATUS_DISPUTED:
                return handleDisputedTransaction($request);
            case REXSHOP_STATUS_REVERSED:
                return handleReversedTransaction($request);
            default:
                return rexshop_on_failure();
                break;
        }
    }
}

/**
 * Handles a completed transaction.
 *
 * @param [array] $request
 * @return void
 */
function handleCompletedTransaction($request)
{
    global $db;

    $userId = rexshop_uid_from_custom($request);
    if (!isset($userId) || $userId < 1) {
        return rexshop_on_failure();
    }

    //Figure out what usergroup the user is receiving.
    $usergroup = rexshop_purchased_usergroup($request);
    if (!isset($usergroup) || $usergroup < 1) {
        return rexshop_on_failure();
    }

    //How much seconds the user has remaining & expire old purchases.
    $remainingSeconds = rexshop_remaining_seconds($userId);

    //Figure out how much seconds the user has purchased.
    $purchasedSeconds = rexshop_purchased_seconds($request, $request['order']['products'][0]['sku']);

    $newEnddate = $purchasedSeconds < 0 ? -1 : TIME_NOW + $remainingSeconds + $purchasedSeconds;

    rexshop_store_transaction($request, $userId, $newEnddate, $request['order']['products'][0]['sku']);

    $banned_groups = [];
    $q = $db->simple_select('usergroups', 'gid', 'isbannedgroup=1');
    while ($gid = $db->fetch_field($q, 'gid')) {
        $banned_groups[] = (int) $gid;
    }

    if (in_array($usergroup, $banned_groups)) {   //Move the user to the purchased usergroup if they get unbanned.
        rexshop_change_usergroup($userId, $usergroup);
    } else { //Move the user to the purchased usergroup.
        rexshop_change_on_unban_usergroup($userId, $usergroup);
    }

    rexshop_send_pm("Successful Purchase", "Thank you for purchasing {$request['order']['products'][0]['name']}.\r\nYou are now upgraded.", $userId);

    return rexshop_on_success();
}

/**
 * Handles a pending transaction (funds are being processed by the payment gateway).
 *
 * @param [array] $request
 * @return void
 */
function handlePendingTransaction($request)
{
    $userId = rexshop_uid_from_custom($request);
    if (!isset($userId) || $userId < 1) {
        return rexshop_on_failure();
    }

    rexshop_send_pm("Pending Purchase", "Your subscription payment is currently pending. You will receive your membership as soon as it clears.", $userId);

    return rexshop_on_success();
}

/**
 * Handles a refunded transaction (you voluntarily refunded the transaction).
 *
 * @param [array] $request
 * @return void
 */
function handleRefundedTransaction($request)
{
    global $db;

    $userId = rexshop_uid_from_custom($request);
    if (!isset($userId) || $userId < 1) {
        return rexshop_on_failure();
    }

    //Figure out what usergroup the user is receiving.
    $usergroup = rexshop_purchased_usergroup($request);
    if (!isset($usergroup) || $usergroup < 1) {
        return rexshop_on_failure();
    }

    //Check how many seconds was bought
    $purchasedTime = rexshop_purchased_seconds($request, $request['order']['products'][0]['sku']);

    //Check what the price per second is
    $pricePerSecond = $purchasedTime / $request['order']['amount'];

    //Check how much money was refunded
    $refundedAmount = $request['refund']['amount'];

    //Figure out what that is in seconds.
    $refundedSeconds = $refundedAmount * $pricePerSecond;

    //Check how much time the user has left
    $remainingSeconds = rexshop_remaining_seconds($userId, false);

    //Subtract the time that was refunded.
    $newEndDate = TIME_NOW + ($remainingSeconds - $refundedSeconds);

    //Check if the subscription is now expired
    $expired = ($newEndDate <= TIME_NOW);

    //Update the users subscription.
    $db->query("UPDATE `" . TABLE_PREFIX . "rexshop_logs` SET `enddate`='" . $newEndDate . "', `expired`='" . $expired . "' WHERE `uid`='" . $userId . "' ORDER BY `id` DESC LIMIT 1");

    //Store the webhook message.
    rexshop_store_transaction($request, $userId, 0, $request['order']['products'][0]['sku']);

    //Notify the user of the refunded purchase in a private message.
    rexshop_send_pm("Refund Received", "You have been refunded: {$request['order']['currency']['symbol']}{$refundedAmount}.\r\nYour remaining time has been adjusted accordingly.", $userId);

    //Notify the user that their subscription has expired.
    if ($expired) {
        rexshop_change_usergroup($userId, REXSHOP_USERGROUP_EXPIRED);
        rexshop_send_pm("Subscription Expired", "Your subscription has expired.", $userId);
    }

    return rexshop_on_success();
}

/**
 * Handles a canceled disputed transaction (merchant won the dispute).
 *
 * @param [array] $request
 * @return void
 */
function handleDisputeCanceledTransaction($request)
{
    global $db;

    $userId = rexshop_uid_from_custom($request);
    if (!isset($userId) || $userId < 1) {
        return rexshop_on_failure();
    }

    $query = $db->query("SELECT uid,usergroup FROM `" . TABLE_PREFIX . "users` WHERE `uid`='" . $userId . "' LIMIT 1");
    if ($db->num_rows($query) <= 0) {
        return rexshop_on_failure();
    }

    $currentUsergroup = $db->fetch_field($query, "usergroup");

    $banned_groups = [];
    $q = $db->simple_select('usergroups', 'gid', 'isbannedgroup=1');
    while ($gid = $db->fetch_field($q, 'gid')) {
        $banned_groups[] = (int) $gid;
    }

    if (in_array($currentUsergroup, $banned_groups)) {
        rexshop_unban_user($userId);
    }

    $completed = REXSHOP_STATUS_COMPLETED;

    $suspendedSecondsQuery = $db->query("SELECT * FROM `" . TABLE_PREFIX . "rexshop_logs` WHERE `transaction_id`='" .  rexshop_regex_escape($request['order']['transaction_id'], '/[^a-zA-Z0-9]/') . "' AND `transaction_status`='" . (int) $completed . "'");
    $suspendedSeconds = $db->fetch_field($suspendedSecondsQuery, "suspended_seconds");
    $currentEnddate = $db->fetch_field($suspendedSecondsQuery, "enddate");

    $db->update_query("rexshop_logs", [
        'enddate' => TIME_NOW >= intval($currentEnddate) ? TIME_NOW + intval($suspendedSeconds) : intval($currentEnddate) + intval($suspendedSeconds),
        'suspended_seconds' => 0,
        'expired' => 0,
    ], "transaction_id='" . rexshop_regex_escape($request['order']['transaction_id'], '/[^a-zA-Z0-9]/') . "' AND `transaction_status`='" . (int) $completed . "'");

    //Figure out what usergroup the user is receiving.
    $usergroup = rexshop_purchased_usergroup($request);
    if (!isset($usergroup) || $usergroup < 1) {
        return rexshop_on_failure();
    }

    //The users usergroup needs to be changed
    if ($usergroup !== $currentUsergroup) {
        rexshop_change_usergroup($userId, $usergroup);
    }

    rexshop_send_pm("Dispute RESOLVED", "You have closed your dispute.\r\nYour subscription has been resumed. Thank you!", $userId);

    return rexshop_on_success();
}

/**
 * Handles a disputed transaction.
 *
 * @param [array] $request
 * @return void
 */
function handleDisputedTransaction($request)
{
    global $db;

    $userId = rexshop_uid_from_custom($request);
    if (!isset($userId) || $userId < 1) {
        return rexshop_on_failure();
    }

    $query = $db->query("SELECT uid,usergroup FROM `" . TABLE_PREFIX . "users` WHERE `uid`='" . $userId . "' LIMIT 1");
    if ($db->num_rows($query) <= 0) {
        return rexshop_on_failure();
    }

    $user = $db->fetch_array($query, PDO::FETCH_ASSOC);

    $banned_groups = [];
    $q = $db->simple_select('usergroups', 'gid', 'isbannedgroup=1');
    while ($gid = $db->fetch_field($q, 'gid')) {
        $banned_groups[] = (int) $gid;
    }

    //The user is not currently banned, unban.
    if (!in_array($user['usergroup'], $banned_groups)) {
        rexshop_ban_user($user);
    }

    //Figure out what usergroup the user is receiving.
    $usergroup = rexshop_purchased_usergroup($request);
    if (!isset($usergroup) || $usergroup < 1) {
        return rexshop_on_failure();
    }

    //Figure out how much time to suspend 
    $secondsToSuspend = rexshop_purchased_seconds($request, $usergroup);

    //Figure out how much time the user has left.
    $remainingSeconds = rexshop_remaining_seconds($userId, false);

    $completed = REXSHOP_STATUS_COMPLETED;

    if (($remainingSeconds - $secondsToSuspend) <= 0) {
        $db->query("UPDATE `" . TABLE_PREFIX . "rexshop_logs` SET `expired`='1' WHERE `transaction_id`='" . rexshop_regex_escape($request['order']['transaction_id'], '/[^a-zA-Z0-9]/') . "' AND `transaction_status`='" . $completed . "'");
        rexshop_change_usergroup($userId, REXSHOP_USERGROUP_EXPIRED);
    }

    rexshop_send_pm("Open Subscription Dispute", "You have opened a dispute. Please resolve this in ban appeals ASAP.", (int) $userId);

    return rexshop_on_success();
}

/**
 * Handles a reversed transaction (merchant lost the dispute).
 *
 * @param [array] $request
 * @return void
 */
function handleReversedTransaction($request)
{
    $userId = rexshop_uid_from_custom($request);
    if (!isset($userId) || $userId < 1) {
        return rexshop_on_failure();
    }

    rexshop_send_pm("Dispute Closed", "The dispute has been closed in your favor. You will remain banned. If you wish to resolve it in ban appeals later feel free to contact us.", $userId);

    return rexshop_on_success();
}


/**
 * Fetches the customer's user id from custom parameter in webhook.
 *
 * @param [array] $request
 * @return integer
 */
function rexshop_uid_from_custom($request)
{
    $userId = -1;

    if (!isset($request['custom'])) {
        return $userId;
    }

    $decoded = unserialize(base64_decode($request['custom']));

    return (int) $decoded['uid'] ?? (int) $userId;
}

function rexshop_purchased_usergroup($request)
{
    global $db;

    $usergroup = -1;

    foreach ($request['products'] as $product) {
        foreach ($product['addons'] as $addon) {
            if (strtolower($addon['name']) !== 'usergroup') {
                continue;
            }

            if (is_numeric($addon['value'])) {
                $usergroup = intval($addon['value']);

                break 2;
            }

            $query = $db->query("SELECT * FROM `" . TABLE_PREFIX . "usergroups` WHERE LOWER(`title`)='" . strtolower($usergroup) . "' LIMIT 1");
            if ($db->num_rows($query) > 0) {
                $usergroup = (int) $db->fetch_field($query, "gid");
            }

            break 2;
        }
    }

    return $usergroup;
}

function rexshop_products_usergroup($product)
{
    global $db;

    $usergroup = -1;

    if (isset($product['prices'])) {
        foreach ($product['prices'] as $price) {
            foreach ($price['addons'] as $addon) {
                if (strtolower($addon['name']) !== 'usergroup') {
                    continue;
                }

                if (is_numeric($addon['value'])) {
                    $usergroup = intval($addon['value']);

                    break 2;
                }

                $query = $db->query("SELECT * FROM `" . TABLE_PREFIX . "usergroups` WHERE LOWER(`title`)='" . strtolower($usergroup) . "' LIMIT 1");
                if ($db->num_rows($query) > 0) {
                    $usergroup = (int) $db->fetch_field($query, "gid");
                }

                break 2;
            }
        }
    } else if (isset($product['addons'])) {
        foreach ($product['addons'] as $addon) {
            if (strtolower($addon['name']) !== 'usergroup') {
                continue;
            }

            if (is_numeric($addon['value'])) {
                $usergroup = intval($addon['value']);

                break;
            }

            $query = $db->query("SELECT * FROM `" . TABLE_PREFIX . "usergroups` WHERE LOWER(`title`)='" . strtolower($usergroup) . "' LIMIT 1");
            if ($db->num_rows($query) > 0) {
                $usergroup = (int) $db->fetch_field($query, "gid");
            }

            break;
        }
    }


    return $usergroup;
}

/**
 * Fetches the remaining subscription seconds for a user.
 *
 * @param [integer] $userId
 * @param boolean $expiresExisting
 * @return integer
 */
function rexshop_remaining_seconds($userId, $expireExisting = true)
{
    global $db;

    $remainingSeconds = 0;

    $query = $db->query("SELECT * FROM `" . TABLE_PREFIX . "rexshop_logs` WHERE `uid`='" . (int) $userId . "' AND `expired`='0'");
    $resultCount = $db->num_rows($query);
    if ($resultCount <= 0) {
        return $remainingSeconds;
    }

    for ($i = 0; $i < $resultCount; $i++) {
        if (strtolower($db->fetch_field($query, "transaction_status", $i)) !== REXSHOP_STATUS_COMPLETED) {
            continue;
        }

        $remainingSeconds += $db->fetch_field($query, "enddate", $i) - TIME_NOW;
    }

    if ($expireExisting === true) {
        $db->query("UPDATE `" . TABLE_PREFIX . "rexshop_logs` SET `expired`='1' WHERE `uid`='" . (int) $userId . "'");
    }

    return intval($remainingSeconds);
}

/**
 * Fetches the purchased sub seconds
 *
 * @param [array] $request
 * @param [string] $sku
 * @return integer
 */
function rexshop_purchased_seconds($request, $sku)
{
    $purchasedSeconds = 0;

    $pricePerSeconds = 1;
    foreach ($request['order']['products'] as $product) {
        if (strtolower($product['sku']) == strtolower($sku)) {
            continue;
        }

        $pricePerSeconds = rexshop_price_per_seconds($product['price'], $product['total_purchased_seconds']);
        break;
    }

    foreach ($request['order']['products'] as $product) {
        if (strtolower($product['sku']) == strtolower($sku)) {
            if ($product['total_purchased_seconds'] < 0) {
                return -1;
            }

            $purchasedSeconds += $product['total_purchased_seconds'];
            continue;
        }

        $productPricePerSecond = rexshop_price_per_seconds($product['price'], $product['total_purchased_seconds']);

        $purchasedSeconds += ($pricePerSeconds / $productPricePerSecond) * $product['total_purchased_seconds'];
    }

    return intval($purchasedSeconds);
}

/**
 * Calculates the price per second.
 *
 * @param [float] $price
 * @param [int] $seconds
 * @return float
 */
function rexshop_price_per_seconds($price, $seconds)
{
    if ($seconds < 0) {
        return $price;
    }

    return $price / $seconds;
}

/*************************************************************************************/
// ADMIN MENU
/*************************************************************************************/

function rexshop_admin_user_menu(&$sub_menu)
{
    global $lang;

    $lang->load('rexshop');
    $sub_menu[] = array('id' => 'rexshop', 'title' => $lang->rexshop_index, 'link' => 'index.php?module=user-rexshop');
}

function rexshop_admin_user_action_handler(&$actions)
{
    $actions['rexshop'] = array('active' => 'rexshop', 'file' => 'rexshop');
}

function rexshop_admin_permissions(&$admin_permissions)
{
    global $lang;

    $lang->load("rexshop", false, true);
    $admin_permissions['rexshop'] = $lang->rexshop_canmanage;
}

function rexshop_messageredirect($message, $error = 0, $action = '')
{
    if (strlen($message) <= 0) {
        return;
    }

    if ($action) {
        $parameters = '&amp;action=' . $action;
    }

    if ($error) {
        flash_message($message, 'error');
        admin_redirect("index.php?module=user-rexshop" . $parameters);
    } else {
        flash_message($message, 'success');
        admin_redirect("index.php?module=user-rexshop" . $parameters);
    }
}

function rexshop_admin()
{
    global $db, $lang, $mybb, $page, $run_module, $action_file;

    if ($run_module != 'user' || $action_file != 'rexshop') {
        return;
    }

    if ($mybb->request_method == "post") {
        verify_post_check($mybb->input['my_post_key']);

        if (strtolower($mybb->input['action']) === 'upgrade') {
            $query = $db->query("SELECT uid,username,usergroup FROM `" . TABLE_PREFIX . "users` WHERE LOWER(`username`)='" . strtolower($db->escape_string($mybb->input['username'])) . "' LIMIT 1");
            if ($db->num_rows($query) <= 0) {
                redirect('index.php?module=user-rexshop', $lang->error_invalid_username);
            }

            $userId = (int) $db->fetch_field($query, "uid");
            if (!isset($userId) || $userId < 1) {
                redirect('index.php?module=user-rexshop', $lang->error_invalid_username);
            }

            $products = rexshop_fetch_products();

            $selectedProduct = null;
            foreach ($products as $product) {
                foreach ($product['prices'] as $price) {
                    if ($price['plan_id'] != $mybb->input['plan_id']) {
                        continue;
                    }

                    $product['prices'] = array_values(array_filter($product['prices'], function ($price) use ($mybb) {
                        return $price['plan_id'] == $mybb->input['plan_id'];
                    }));

                    $selectedProduct = $product;
                    break;
                }
            }

            if (!isset($selectedProduct) || empty($selectedProduct['prices'])) {
                redirect('index.php?module=user-rexshop', $lang->error_invalid_subscription);
            }

            $usergroup = rexshop_products_usergroup($selectedProduct);

            if ($usergroup < 1) {
                redirect('index.php?module=user-rexshop', $lang->error_invalid_subscription);
            }

            $remainingSeconds = rexshop_remaining_seconds($userId);
            $purchasedSeconds = rexshop_seconds_from_duration($selectedProduct['prices'][0]['duration'], $selectedProduct['prices'][0]['time']);

            $enddate = $purchasedSeconds <= -1 ? -1 : TIME_NOW + $remainingSeconds + $purchasedSeconds;
            $payload = [
                'uid' => $userId,
                'product_sku' => null,
                'transaction_id' => null,
                'transaction_status' => REXSHOP_STATUS_COMPLETED,
                'transaction_from' => time(),
                'country' => null,
            ];

            rexshop_store_transaction($payload, $userId, $enddate);
            rexshop_change_usergroup($userId, $usergroup);

            rexshop_send_pm("Gift Received!", "You have received a gift and have been upgraded.", $userId);

            redirect('index.php?module=user-rexshop', $lang->rexshop_gift_was_sent);
        }
    }

    $lang->load("rexshop", false, true);

    $page->add_breadcrumb_item($lang->rexshop, 'index.php?module=user-rexshop');
    $page->output_header($lang->rexshop);
    $page->output_nav_tabs([
        'rexshop' => [
            'title' => $lang->rexshop_gift,
            'link' => 'index.php?module=user-rexshop',
            'description' => $lang->rexshop_gift_desc
        ]
    ], 'rexshop');

    if (!$mybb->input['action']) { //Gifts
        $products = rexshop_fetch_products();

        $query = $db->simple_select("usergroups", "gid, title", "gid != '1'", ['order_by' => 'title']);
        while ($usergroup = $db->fetch_array($query)) {
            $groups[$usergroup['gid']] = $usergroup['title'];
        }

        $form = new Form("index.php?module=user-rexshop&amp;action=upgrade", "post", "rexshop");
        $form_container = new FormContainer($lang->rexshop_upgrade_user);
        $form_container->output_row($lang->rexshop_username, '', $form->generate_text_box('username', '', ['id' => 'username']), 'username');
        $productLists = [];
        foreach ($products as $product) {
            foreach ($product['prices'] as $price) {
                $productLists[$price['plan_id']] = "{$product['name']} - {$price['name']} ({$price['time']} {$price['duration']})";
            }
        }

        $form_container->output_row($lang->rexshop_plan, '', $form->generate_select_box('plan_id', $productLists, '', ['id' => 'plan_id']), 'plan_id');
        $form_container->end();

        $buttons = [];
        $buttons[] = $form->generate_submit_button($lang->rexshop_give_upgrade);
        $form->output_submit_wrapper($buttons);
        $form->end();

        echo "<br>";

        $table = new Table;
        $table->construct_header($lang->rexshop_title, ['width' => '25%']);
        $table->construct_header($lang->rexshop_group, ['width' => '25%', 'class' => 'align_center']);
        $table->construct_header($lang->rexshop_period, ['width' => '25%', 'class' => 'align_center']);
        $table->construct_header($lang->rexshop_price, ['width' => '25%', 'class' => 'align_center']);

        foreach ($products as $product) {
            foreach ($product['prices'] as $price) {
                $table->construct_cell("<div>{$product['name']} - {$price['name']}</div>");

                $purchasedUsergroup = rexshop_products_usergroup($price);

                if (isset($purchasedUsergroup) && isset($groups[$purchasedUsergroup])) {
                    $table->construct_cell(htmlspecialchars_uni($groups[$purchasedUsergroup]), ['class' => 'align_center']);
                } else {
                    $table->construct_cell("Unknown Usergroup. <a href='https://shop.rexdigital.group/how-does-product-addons-work' target='_blank' rel='noopener'><u>Help</u></a>", ['class' => 'align_center']);
                }

                $table->construct_cell($price['time'] . " " . $price['duration'], ['class' => 'align_center']);

                $table->construct_cell($price['currency'] . $price['price'], ['class' => 'align_center']);

                $table->construct_row();
            }
        }

        if ($table->num_rows() == 0) {
            $table->construct_cell($lang->rexshop_no_subs, ['colspan' => 5]);
            $table->construct_row();
        }

        $table->output($lang->rexshop_plans);
    }

    $page->output_footer();
    exit;
}

/*************************************************************************************/
// UTILITIES
/*************************************************************************************/

/**
 * Checks if this transaction has already been registered in the system.
 *
 * @param [array] $request
 * @return boolean
 */
function rexshop_transaction_duplicate($request)
{
    global $db;

    $query = $db->query("SELECT COUNT(*) as resultCount FROM `" . TABLE_PREFIX . "rexshop_logs` WHERE `transaction_id`='" . rexshop_regex_escape($request['order']['transaction_id'], '/[^a-zA-Z0-9]/') . "' AND `transaction_status`='" . rexshop_regex_escape($request['status'], '/[^a-zA-Z0-9]/') . "' LIMIT 1");

    return $db->fetch_field($query, "resultCount") > 0;
}

/**
 * Verify the origin of the webhook message was from rex digital shop.
 *
 * @param [array] $request
 * @return boolean
 */
function rexshop_verify_webhook($request)
{
    global $mybb;

    return $request['RDG_WH_SIGNATURE'] === hash_hmac(
        'sha256',
        $request['order']['transaction_id'] . $request['status'],
        $mybb->settings['rexshop_secret']
    );
}

/**
 * Changes the users usergroup.
 *
 * @param [integer] $userId
 * @param [integer] $newGroupId
 * @return void
 */
function rexshop_change_usergroup($userId, $usergroup)
{
    global $db;

    $db->query("UPDATE `" . TABLE_PREFIX . "users` SET `usergroup`='" . (int) $usergroup . "', `displaygroup`='0' WHERE `uid`='" . (int) $userId . "'");
}

/**
 * Changes the usergroup the user gets moved to on an unban.
 *
 * @param [integer] $userId
 * @param [integer] $usergroup
 * @return void
 */
function rexshop_change_on_unban_usergroup($userId, $usergroup)
{
    global $db;

    $db->update_query("banned", ['oldgroup' => intval($usergroup)], "uid='" . (int) $userId . "' AND lifted='0'");
}

/**
 * Unbans a user.
 *
 * @param [array] $user
 * @param string $reason
 * @return boolean
 */
function rexshop_unban_user($userId, $reason = null)
{
    global $db;

    if (is_null($reason)) {
        $db->update_query("banned", ['lifted' => 1], "uid='" . (int) $userId . "'");
    } else {
        $db->update_query("banned", ['lifted' => 1], "uid='" . (int) $userId . "' AND reason='" . rexshop_regex_escape($reason, '/[^a-zA-Z0-9 \.\,\!\(\)]/') . "'");
    }

    $oldGroupQuery = $db->query("SELECT `id`,`uid`,`oldgroup` FROM `" . TABLE_PREFIX . "banned` WHERE `uid`='" . $userId . "' ORDER BY `id` DESC LIMIT 1");
    $isStillBannedQuery = $db->query("SELECT * FROM `" . TABLE_PREFIX . "banned` WHERE `lifted`='0' AND `uid`='" . $userId . "' LIMIT 1");
    if ($db->num_rows($isStillBannedQuery) <= 0) {
        $newUsergroup = $db->num_rows($oldGroupQuery) > 0 ? $db->fetch_field($oldGroupQuery, "oldgroup") : REXSHOP_USERGROUP_EXPIRED;
        rexshop_change_usergroup($userId, $newUsergroup);

        return true;
    }

    return false;
}

/**
 * Bans a user.
 *
 * @param [array] $user
 * @param string $reason
 * @param string $banTime
 * @param integer $bannedBy
 * @return boolean
 */
function rexshop_ban_user($user, $reason = BAN_REASON_CHARGEBACK, $banTime = '---', $bannedBy = 1)
{
    global $db;

    $banned_groups = [];
    $q = $db->simple_select('usergroups', 'gid', 'isbannedgroup=1');
    while ($gid = $db->fetch_field($q, 'gid')) {
        $banned_groups[] = (int) $gid;
    }

    rexshop_change_usergroup($user, $banned_groups[0]);

    $db->insert_query("banned", [
        'uid' => (int) $user['uid'],
        'gid' => (int) $banned_groups[0],
        'oldgroup' => (int) $user['usergroup'],
        'oldadditionalgroups' => "",
        'olddisplaygroup' => 0,
        'admin' => (int) $bannedBy,
        'dateline' => (int) TIME_NOW,
        'bantime' => rexshop_regex_escape($banTime, '/[^\-]/'),
        'lifted' => 0,
        'reason' => rexshop_regex_escape($reason, '/[^a-zA-Z0-9 \.\,\!\(\)]/'),
    ]);
}

/**
 * Stores the transaction in the database.
 *
 * @param [array] $request
 * @param [integer] $uid
 * @param [integer] $enddate
 * @return void
 */
function rexshop_store_transaction($request, $uid, $enddate, $productSku = null)
{
    global $db;

    $db->insert_query("rexshop_logs", [
        'uid' => intval($uid),
        'product_sku' => rexshop_regex_escape($productSku, '/[^a-zA-Z0-9]/'),
        'transaction_id' => rexshop_regex_escape($request['order']['transaction_id'], '/[^a-zA-Z0-9]/'),
        'transaction_status' => rexshop_regex_escape($request['transaction_status'], '/[^a-zA-Z0-9]/'),
        'transaction_from' => (int) $request['order']['initiated_at'],
        'country' => rexshop_regex_escape($request['customer']['country'], '/[^a-zA-Z]/'),
        'enddate' => (int) $enddate,
        'expired' => $enddate <= TIME_NOW && $enddate > -1 ? 1 : 0
    ]);
}

/**
 * returns amount of seconds from duration and time values
 *
 * @param [string] $duration
 * @param [int] $time
 * @return integer
 */
function rexshop_seconds_from_duration($duration, $time)
{
    switch (strtolower($duration)) {
        case 'day':
        case 'days':
            return SECONDS_PER_DAY * $time;
        case 'week':
        case 'weeks':
            return SECONDS_PER_DAY * 7 * $time;
        case 'month':
        case 'months':
            return SECONDS_PER_DAY * 28 * $time;
        case 'year':
        case 'years':
            return SECONDS_PER_DAY * 365 * $time;
        case 'unlimited':
        case 'lifetime':
            return -1;
        default:
            return 0;
    }
}

function rexshop_allowed_to_buy($usergroup)
{
    global $mybb;

    if (!empty($mybb->settings['rexshop_exclude_usergroups'])) {
        $usergroups = explode(',', ltrim(rtrim(trim($mybb->settings['rexshop_exclude_usergroups'], ' '), ','), ','));

        return !in_array((int) $usergroup, $usergroups);
    }

    return true;
}

function rexshop_on_success()
{
    header("Status: 200 OK");
    exit;
}

function rexshop_on_failure()
{
    header('Status: 400 Bad Request');
    exit;
}

function rexshop_fetch_products()
{
    global $mybb;

    if (!isset($mybb->settings['rexshop_api_key'])) {
        return [];
    }

    $ch = curl_init("https://shop.rexdigital.group/api/v1/products?api_key=" . rexshop_regex_escape($mybb->settings['rexshop_api_key'], '/[^a-zA-Z0-9]/'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);

    return $result['products']['data'] ?? [];
}

function rexshop_regex_escape($input, $regex = null, $replacement = '')
{
    global $db;

    if (!isset($regex)) {
        return $db->escape_string($input);
    }

    return preg_replace($regex, $replacement, $db->escape_string($input));
}

/**
 * Sends private messages to users.
 *
 * @param [string] $subject
 * @param [string] $message
 * @param [array|integer] $userId
 * @param integer $fromId
 * @return boolean
 */
function rexshop_send_pm($subject, $message, $userId, $fromId = 1)
{
    require_once MYBB_ROOT . "inc/datahandlers/pm.php";

    $pmhandler = new PMDataHandler();

    $pmhandler->admin_override = 1;
    $pmhandler->set_data([
        'subject' => $subject,
        'message' => $message,
        'icon' => -1,
        'fromid' => $fromId,
        'toid' => is_array($userId) ? $userId : [$userId],
        'bccid' => [],
        'do' => '',
        'pmid' => '',
        'saveasdraft' => 0,
        'receivepms' => 1,
        'options' => [
            'signature' => 0,
            'disablesmilies' => 0,
            'savecopy' => 0,
            'readreceipt' => 0
        ]
    ]);

    if ($pmhandler->validate_pm()) {
        $pmhandler->insert_pm();
        return true;
    }

    return false;
}
