# Pimcore Azure Bundle

Pimcore Azure Bundle is use to push Pimcore Assets on Microsoft Azure Blob Storage

## Compatible with Pimcore > v5.6. Tested on Pimcore 6

### Installation

Install with Composer
```bash
composer require rohitrajv5/pimcore-bundle-azure-blob-sync
```

#### Execute following commands
```bash
bin/console pimcore:bundle:enable AzurePimcoreBundle
bin/console assets:install web
```
Plugin will look like this

![alt text](https://i.postimg.cc/Gtp6TJkn/Screenshot-from-2020-07-07-13-47-41.png)

##### Changes in Pimcore Admin
1. Goto Pimcore Admin -> Settings -> Azure Blog Container Settings

2. Enter your credentials and save.


## Configurations & Settings

#### Add following code in "app/constant.php" 

```php
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
Uncomment option, Which you want to sync on Azure Blob Storage.

#### Add following code constant in "app/startup.php" 

```php
use AzurePimcoreBundle\AzureBlobStorage\StreamWrapper;
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

## License
----
GPL-3.0+
