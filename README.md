# Pimcore Azure Bundle

Pimcore Azure Bundle is use to push Pimcore Assets on Azure Blob Storage


## Installation

Download project from GitHub
```bash
git clone https://github.com/rohitrajv5/pimcore-bundle-azure-blob-sync.git
```

Copy "src/AzurePimcoreBundle" in your application "src" directory

Add following code in "var/config/azure.php". Replace your credentials here
```bash
<?php 
return [
    "accountUrl" => "https://pcdevstorage.blob.core.windows.net",
    "accountName" => "pcdevstorage",
    "accountKey" => "********************************",
    "container" => "***********************",
    "enableAzure" => FALSE
];
```

Add following code in "app/constant.php" 
```bash
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
```
Add following code constant in "app/startup.php" 
```bash
use AppBundle\AzureBlobStorage\StreamWrapper;
if (ENABLE_AZURE) {
    $accountUrl = AZURE_ACCOUNT_URL;
    $accountName = AZURE_ACCOUNT_NAME;
    $accountKey = AZURE_ACCOUNT_KEY;
    $connectionString = "DefaultEndpointsProtocol=https;AccountName=" . $accountName . ";AccountKey=" . $accountKey;

    $container = AZURE_CONTAINER;

    $blobClient = \MicrosoftAzure\Storage\Blob\BlobRestProxy::createBlobService($connectionString);

    StreamWrapper::register($blobClient, 'blob');

    \Pimcore\File::setContext(stream_context_create([
        'blob' => ['seekable' => true]
    ]));
}
```
Add following code constant in "app/config/services.yml" 
```bash
services:
    # default configuration for services in *this* file
    _defaults:
        # automatically injects dependencies in your services
        autowire: true
        # automatically registers your services as commands, event subscribers, etc.
        autoconfigure: true
        # this means you cannot fetch services directly from the container via $container->get()
        # if you need to do this, you can override this setting on individual services
        public: false

      
    AzurePimcoreBundle\EventListener\AzureListener:
        tags:
            - { name: kernel.event_listener, event: pimcore.frontend.path.asset.image.thumbnail, method: onFrontendPathThumbnail }
            - { name: kernel.event_listener, event: pimcore.frontend.path.asset.document.image-thumbnail, method: onFrontendPathThumbnail }
            - { name: kernel.event_listener, event: pimcore.frontend.path.asset.video.image-thumbnail, method: onFrontendPathThumbnail }
            - { name: kernel.event_listener, event: pimcore.frontend.path.asset.video.thumbnail, method: onFrontendPathThumbnail }
            - { name: kernel.event_listener, event: pimcore.asset.image.thumbnail, method: onAssetThumbnailCreated }
            - { name: kernel.event_listener, event: pimcore.asset.video.image-thumbnail, method: onAssetThumbnailCreated }
            - { name: kernel.event_listener, event: pimcore.asset.document.image-thumbnail, method: onAssetThumbnailCreated }
            - { name: kernel.event_listener, event: pimcore.frontend.path.asset, method: onFrontEndPathAsset }
```

# Features!

Execute following commands
```bash
bin/console pimcore:bundle:enable AzurePimcoreBundle
bin/console assets:install web
```
Plugin will look like this

![alt text](https://i.postimg.cc/Gtp6TJkn/Screenshot-from-2020-07-07-13-47-41.png)

You can enter Azure Blog Storage Detail here as well

# Features!

  - Upload Pimcore Assets on Azure Blob Storage
  - Sync assets versioning 
  - Sync tmp folder

Manage folder sync option from "app/constant.php"
```
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
```
Uncomment option, Which you want to sync on Azure Blob Storage.

License
----

MIT


**Free Software, Hell Yeah! Contact for more updates**


