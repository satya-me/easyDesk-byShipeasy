<?php

// define('IN_SCRIPT',1);
// define('HESK_PATH','../');

// // Get all the required files and functions
// require(HESK_PATH . 'hesk_settings.inc.php');
// require(HESK_PATH . 'inc/common.inc.php');
function handleFileUpload($files, $ticketId)
{
   

    $maxFiles = 5;
    $maxFileSize = 10 * 1024 * 1024; // 10 MB in bytes
    $allowedExtensions = ['gif', 'jpg', 'png', 'zip', 'rar', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'pdf'];
    $uploadDirectory = 'uploads/'; // Ensure this directory exists and is writable

    // Ensure the upload directory exists
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0777, true)) {
        return ['error' => 'Failed to create upload directory'];
    }

    $uploadedFiles = [];
    $errors = [];

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
            $uploadedFiles[] = $uploadPath;

            // Insert file information into the database
            $savedName = basename($uploadPath);
            $realName = basename($files['name'][$i]);
            $size = $fileSize;
            $type = 0; // Assuming 'type' is an integer field with a default value

            // hesk_dbQuery("INSERT INTO `" . hesk_dbEscape($hesk_settings['db_pfix']) . "attachments` (`ticket_id`, `saved_name`, `real_name`, `size`, `type`)
            // VALUES ('" . hesk_dbEscape($ticketId) . "', '" . hesk_dbEscape($savedName) . "','" . hesk_dbEscape($realName) . "','" . hesk_dbEscape($size) . "','" . hesk_dbEscape($type) . "',)");
            
        } else {
            $errors[] = "Failed to upload file {$files['name'][$i]}";
        }
    }

    if (!empty($errors)) {
        return ['error' => $errors];
    }

    return ['success' => $uploadedFiles];
}
