<?php
try {
    $file = __DIR__ . '/../var/config/azure.php';
    if (file_exists($file)) {
        $azureConfig = include($file);
    }
} catch (\Exception $e) {
    $azureConfig = [];
}
$azureEnabled = FALSE;
if (isset($azureConfig['enableAzure']) && $azureConfig['enableAzure']) {
    $azureEnabled = TRUE;
    define("AZURE_ACCOUNT_URL", $azureConfig['accountUrl']);
    define("AZURE_ACCOUNT_NAME", $azureConfig['accountName']);
    define("AZURE_ACCOUNT_KEY", $azureConfig['accountKey']);
    define("AZURE_CONTAINER", $azureConfig['container']);   
    $azureFileWrapperPrefix = "blob://" . AZURE_CONTAINER; // do NOT change    
    define("PIMCORE_ASSET_DIRECTORY", $azureFileWrapperPrefix . "/assets");
    //define("PIMCORE_TEMPORARY_DIRECTORY", $azureFileWrapperPrefix . "/tmp");
    //constants for reference in the views
    //define("PIMCORE_TRANSFORMED_ASSET_URL", AZURE_ACCOUNT_URL . "/" . AZURE_CONTAINER . "/assets");

    // the following paths should be private!
    define("PIMCORE_VERSION_DIRECTORY", $azureFileWrapperPrefix . "/versions");
    //define("PIMCORE_RECYCLEBIN_DIRECTORY", $azureFileWrapperPrefix . "/recyclebin");
    //define("PIMCORE_LOG_MAIL_PERMANENT", $azureFileWrapperPrefix . "/email");
    //define("PIMCORE_LOG_FILEOBJECT_DIRECTORY", $azureFileWrapperPrefix . "/fileobjects");
}

define("ENABLE_AZURE",$azureEnabled);






