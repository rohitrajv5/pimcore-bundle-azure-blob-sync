<?php

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
