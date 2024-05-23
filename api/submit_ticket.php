<?php

/**
 *
 * This file is part of HESK - PHP Help Desk Software This.
 *
 * (c) Copyright Klemen Stirn. All rights reserved.
 * https://www.hesk.com
 *
 * For the full copyright and license agreement information visit
 * https://www.hesk.com/eula.php
 *
 */
header("Access-Control-Allow-Origin: *"); // Allow all origins, or specify a particular origin
header("Access-Control-Allow-Methods: POST, GET, OPTIONS"); // Specify allowed methods
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // Specify allowed headers



define('IN_SCRIPT', 1);
define('HESK_PATH', '../');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get all the required files and functions
require(HESK_PATH . 'hesk_settings.inc.php');
require(HESK_PATH . 'inc/common.inc.php');
// require('attachment.php');

// API response helper functions
function send_json_response($data, $status_code = 200)
{
    if (ob_get_length()) {
        ob_clean();
    }
    session_write_close();
    header('Content-Type: application/json');
    http_response_code($status_code);
    echo json_encode($data);
    exit();
}
// send_json_response($_POST);
// exit;



// ----------------------------------------------------------------------------------

// Are we in maintenance mode?
hesk_check_maintenance();

// Are we in "Knowledgebase only" mode?
hesk_check_kb_only();

hesk_load_database_functions();
require(HESK_PATH . 'inc/email_functions.inc.php');
require(HESK_PATH . 'inc/posting_functions.inc.php');



// We only allow POST requests to this file
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    send_json_response(['error' => 'Only POST requests are allowed'], 405);
}

// Check for POST requests larger than what the server can handle
if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    send_json_response(['error' => $hesklang['maxpost']], 413);
}

// Block obvious spammers trying to inject email headers
if (preg_match("/\n|\r|\t|%0A|%0D|%08|%09/", hesk_POST('name') . hesk_POST('subject'))) {
    send_json_response(['error' => 'Forbidden'], 403);
}

hesk_session_start();

// Connect to database
hesk_dbConnect();

// function



// Function to handle file uploads
function handleFileUpload($files, $ticketId, $hesk_settings)
{
    $maxFiles = 5;
    $maxFileSize = 10 * 1024 * 1024; // 10 MB in bytes
    $allowedExtensions = ['gif', 'jpg', 'png', 'zip', 'rar', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'pdf'];
    $uploadDirectory = '../attachments/'; // Ensure this directory exists and is writable

    // Ensure the upload directory exists
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true)) {
        return ['error' => 'Failed to create upload directory'];
    }

    $uploadedFiles = [];
    $errors = [];
    $attachments = "";

    // Check the number of files
    if (count($files['name']) > $maxFiles) {
        return ['error' => 'You can upload a maximum of 5 files'];
    }

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "Error uploading file {$files['name'][$i]}";
            continue;
        }

        $fileSize = $files['size'][$i];
        if ($fileSize > $maxFileSize) {
            $errors[] = "File {$files['name'][$i]} is too large. Maximum size is 10 MB.";
            continue;
        }

        $fileExtension = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
        if (!in_array($fileExtension, $allowedExtensions)) {
            $errors[] = "File {$files['name'][$i]} has an invalid file type.";
            continue;
        }

        // Generate a new file name
        $timestamp = (new DateTime())->format('Y_m_d\TH_i_s_v\Z');
        $newFileName = "image_{$timestamp}." . $fileExtension;
        $uploadPath = $uploadDirectory . $newFileName;

        if (move_uploaded_file($files['tmp_name'][$i], $uploadPath)) {

            // Insert file information into the database
            $savedName = basename($uploadPath);
            $realName = basename($files['name'][$i]);
            $size = $fileSize;
            $type = 0; // Assuming 'type' is an integer field with a default value

            hesk_dbQuery("INSERT INTO `" . hesk_dbEscape($hesk_settings['db_pfix']) . "attachments` (`ticket_id`, `saved_name`, `real_name`, `size`, `type`)
                    VALUES ('" . hesk_dbEscape($ticketId) . "', '" . hesk_dbEscape($savedName) . "','" . hesk_dbEscape($realName) . "','" . hesk_dbEscape($size) . "','" . hesk_dbEscape($type) . "')");
            // $tmpvar['attachments'] .= hesk_dbInsertID() . '#' . $realName .',';

            $attachments .= hesk_dbInsertID() . '#' . $realName . ',';
        } else {
            $errors[] = "Failed to upload file {$files['name'][$i]}";
        }
    }

    if (!empty($errors)) {
        return ['error' => $errors];
    }

    return ['success' => $attachments];
}


$hesk_error_buffer = array();

// Check anti-SPAM question
if ($hesk_settings['question_use']) {
    $question = hesk_input(hesk_POST('question'));

    if (strlen($question) == 0) {
        $hesk_error_buffer['question'] = $hesklang['q_miss'];
    } elseif (hesk_mb_strtolower($question) != hesk_mb_strtolower($hesk_settings['question_ans'])) {
        $hesk_error_buffer['question'] = $hesklang['q_wrng'];
    } else {
        $_SESSION['c_question'] = $question;
    }
}

$tmpvar['name'] = hesk_input(hesk_POST('name')) or $hesk_error_buffer['name'] = $hesklang['enter_your_name'];

$email_available = true;

if ($hesk_settings['require_email']) {
    $tmpvar['email'] = hesk_validateEmail(hesk_POST('email'), 'ERR', 0) or $hesk_error_buffer['email'] = $hesklang['enter_valid_email'];
} else {
    $tmpvar['email'] = hesk_validateEmail(hesk_POST('email'), 'ERR', 0);

    // Not required, but must be valid if it is entered
    if ($tmpvar['email'] == '') {
        $email_available = false;

        if (strlen(hesk_POST('email'))) {
            $hesk_error_buffer['email'] = $hesklang['not_valid_email'];
        }

        // No need to confirm the email
        $hesk_settings['confirm_email'] = 0;
        $_POST['email2'] = '';
        $_SESSION['c_email'] = '';
        $_SESSION['c_email2'] = '';
    }
}

if ($hesk_settings['confirm_email']) {
    $tmpvar['email2'] = hesk_validateEmail(hesk_POST('email2'), 'ERR', 0) or $hesk_error_buffer['email2'] = $hesklang['confemail2'];

    // Anything entered as email confirmation?
    if ($tmpvar['email2'] != '') {
        // Do we have multiple emails?
        if ($hesk_settings['multi_eml'] && count(array_diff(explode(',', strtolower($tmpvar['email'])), explode(',', strtolower($tmpvar['email2'])))) == 0) {
            $_SESSION['c_email2'] = hesk_POST('email2');
        }
        // Single email address match
        elseif (!$hesk_settings['multi_eml'] && strtolower($tmpvar['email']) == strtolower($tmpvar['email2'])) {
            $_SESSION['c_email2'] = hesk_POST('email2');
        } else {
            // Invalid match
            $tmpvar['email2'] = '';
            $_POST['email2'] = '';
            $_SESSION['c_email2'] = '';
            $_SESSION['isnotice'][] = 'email';
            $hesk_error_buffer['email2'] = $hesklang['confemaile'];
        }
    } else {
        $_SESSION['c_email2'] = hesk_POST('email2');
    }
}

$tmpvar['category'] = intval(hesk_POST('category')) or $hesk_error_buffer['category'] = $hesklang['sel_app_cat'];

// Do we have a default due date?
$default_due_date_info = hesk_getCategoryDueDateInfo($tmpvar['category']);
if ($default_due_date_info !== null) {
    $current_date = new DateTime('today midnight');
    $current_date->add(DateInterval::createFromDateString("+{$default_due_date_info['amount']} {$default_due_date_info['unit']}s"));
    $tmpvar['due_date'] = hesk_datepicker_format_date($current_date->getTimestamp());
}

// Do we allow customer to select priority?
if ($hesk_settings['cust_urgency']) {
    $valid_priorities = array(
        'high' => 1,
        'medium' => 2,
        'low' => 3
    );
    $tmpvar['priority'] = hesk_POST('priority');

    // 0 is an invalid option, so we'll set it to that to let the if block below process normally
    $tmpvar['priority'] = key_exists($tmpvar['priority'], $valid_priorities) ? $valid_priorities[$tmpvar['priority']] : 0;

    // We don't allow customers select "Critical". If priority is not valid set it to "low".
    if ($tmpvar['priority'] < 1 || $tmpvar['priority'] > 3) {
        // If we are showing "Click to select" priority needs to be selected
        if ($hesk_settings['select_pri']) {
            $tmpvar['priority'] = -1;
            $hesk_error_buffer['priority'] = $hesklang['select_priority'];
        } else {
            $tmpvar['priority'] = 3;
        }
    }
}
// Priority will be selected based on the category selected
else {
    $res = hesk_dbQuery("SELECT `priority` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "categories` WHERE `id`=" . intval($tmpvar['category']));
    if (hesk_dbNumRows($res) == 1) {
        $tmpvar['priority'] = hesk_dbResult($res);
    } else {
        send_json_response(['error' => $hesklang['inv_cat']], 400);
    }
}



$tmpvar['subject'] = hesk_input(hesk_POST('subject')) or $hesk_error_buffer['subject'] = $hesklang['enter_ticket_subject'];

$tmpvar['message'] = hesk_input(hesk_POST('message')) or $hesk_error_buffer['message'] = $hesklang['enter_message'];

// Is category a valid choice?
$res = hesk_dbQuery("SELECT `autoassign` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "categories` WHERE `id`='{$tmpvar['category']}' LIMIT 1");
if (hesk_dbNumRows($res) != 1) {
    $hesk_error_buffer['category'] = $hesklang['sel_app_cat'];
} else {
    $row = hesk_dbFetchAssoc($res);
    $autoassign = $row['autoassign'];
}









// If we have any errors lets store info in session to avoid retyping everything
if (count($hesk_error_buffer)) {
    $_SESSION['iserror'] = array_keys($hesk_error_buffer);
    $_SESSION['c_category'] = $tmpvar['category'];
    $_SESSION['c_priority'] = $tmpvar['priority'];
    $_SESSION['c_subject'] = $tmpvar['subject'];
    $_SESSION['c_message'] = $tmpvar['message'];

    if ($email_available) {
        $_SESSION['c_email'] = $tmpvar['email'];
    }

    if ($hesk_settings['confirm_email']) {
        $_SESSION['c_email2'] = $tmpvar['email2'];
    }

    $_SESSION['isnotice'] = array_keys($hesk_error_buffer);
    $errors = implode(', ', array_keys($hesk_error_buffer));
    send_json_response(['error' => 'Please correct the following fields: ' . $errors], 400);
}

// Generate tracking ID
$tmpvar['trackid'] = hesk_createID();
$tmpvar['status'] = 0;
$tmpvar['owner'] = 0;
$tmpvar['openedby'] = 0;
$tmpvar['firstreply'] = 0;
$tmpvar['lastreply'] = 0;
$tmpvar['replies'] = 0;
$tmpvar['staffreplies'] = 0;
$tmpvar['history'] = '';
$tmpvar['custom1'] = '';
$tmpvar['custom2'] = '';
$tmpvar['custom3'] = '';
$tmpvar['custom4'] = '';
$tmpvar['custom5'] = '';
$tmpvar['custom6'] = '';
$tmpvar['custom7'] = '';
$tmpvar['custom8'] = '';
$tmpvar['custom9'] = '';
$tmpvar['custom10'] = '';
$tmpvar['custom11'] = '';
$tmpvar['custom12'] = '';
$tmpvar['custom13'] = '';
$tmpvar['custom14'] = '';
$tmpvar['custom15'] = '';
$tmpvar['custom16'] = '';
$tmpvar['custom17'] = '';
$tmpvar['custom18'] = '';
$tmpvar['custom19'] = '';
$tmpvar['custom20'] = '';
$tmpvar['ip'] = hesk_getClientIP();
$tmpvar['language'] = '';
$tmpvar['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
$tmpvar['due_date'] = isset($tmpvar['due_date']) ? $tmpvar['due_date'] : '';

$tmpvar['message'] = hesk_makeURL($tmpvar['message']);
$tmpvar['message'] = nl2br($tmpvar['message']);
$tmpvar['message_html'] = '
<b>Section: </b> ' . htmlspecialchars($_POST['section'], ENT_QUOTES, 'UTF-8') . '<br>
<b>Subject: </b> ' . htmlspecialchars($_POST['subject'], ENT_QUOTES, 'UTF-8') . '<br>
<b>Order No: </b> ' . htmlspecialchars($_POST['order_no'], ENT_QUOTES, 'UTF-8') . '<br><br>
' . nl2br(htmlspecialchars($_POST['message'], ENT_QUOTES, 'UTF-8')) . '
';


// Attachments
$tmpvar['attachments'] = "";

// Example usage of file upload handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attachments'])) {
    $result = handleFileUpload($_FILES['attachments'], $tmpvar['trackid'], $hesk_settings);
    $tmpvar['attachments'] = $result['success'];
    // 30#OIG2.4jT8UxPogPu7jtazXqhS.jpg,31#OIG1.UhpUd87xHiZf3SIma5j2.jpg,
    // send_json_response($result['success']);
}
// Save ticket
$ticket_id = hesk_newTicket($tmpvar);




// Comment out the already submitted session variable
// $_SESSION['already_submitted'] = 1;

if ($hesk_settings['notify_new'] && $autoassign) {
    $autoassign_owner = hesk_autoAssignTicket($tmpvar['category']);
    // print_r($autoassign_owner);
    if ($autoassign_owner) {
        $tmpvar['owner'] = $autoassign_owner['id'];
        hesk_dbQuery("UPDATE `" . hesk_dbEscape($hesk_settings['db_pfix']) . "tickets` SET `owner`='" . intval($tmpvar['owner']) . "' WHERE `id`='" . intval($ticket_id) . "' LIMIT 1");

        // Notify assigned staff
        hesk_notifyAssignedStaff($autoassign_owner, 'ticket_assigned_to_you');
    }
}

// Should we notify the customer?
if ($email_available) {
    // echo "HI";
    hesk_notifyCustomer();
}
// Set reply from name
$ticket = hesk_dbQuery("SELECT `name`, `email`, `category` FROM `" . hesk_dbEscape($hesk_settings['db_pfix']) . "tickets` WHERE `id`='{$ticket_id}' LIMIT 1");
$ticket = hesk_dbFetchAssoc($ticket);

// Send email to the ticket owner
hesk_notifyAssignedStaff($ticket, 'new_ticket_by_staff');

send_json_response(['success' => 'Ticket created successfully', 'trackid' => $tmpvar['trackid']]);
