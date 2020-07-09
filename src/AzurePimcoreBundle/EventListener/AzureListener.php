<?php

namespace AzurePimcoreBundle\EventListener;

use Symfony\Component\EventDispatcher\GenericEvent;

class AzureListener {

    public function __construct() {
        if (ENABLE_AZURE) {
            // you have to customize this if you'd like to deliver your assets/thumbnails in your S3 bucket by CloudFront
            $this->azureBaseUrl = AZURE_ACCOUNT_URL;

            $this->azureTmpUrlPrefix = $this->azureBaseUrl . str_replace("blob:/", "", PIMCORE_TEMPORARY_DIRECTORY);
            $this->azureAssetUrlPrefix = $this->azureBaseUrl . str_replace("blob:/", "", PIMCORE_ASSET_DIRECTORY);
        }
    }

    public function onFrontendPathThumbnail(GenericEvent $event) {
        if (ENABLE_AZURE) {
            // rewrite the path for the frontend
            $fileSystemPath = $event->getSubject()->getFileSystemPath();

            $cacheKey = "thumb_azure_" . md5($fileSystemPath);
            $path = \Pimcore\Cache::load($cacheKey);

            if (!$path) {
                if (!file_exists($fileSystemPath)) {
                    // the thumbnail doesn't exist yet, so we need to create it on request -> Thumbnail controller plugin
                    $path = str_replace(PIMCORE_TEMPORARY_DIRECTORY . "/image-thumbnails", "", $fileSystemPath);
                } else {
                    $path = str_replace(PIMCORE_TEMPORARY_DIRECTORY . "/", $this->azureTmpUrlPrefix . "/", $fileSystemPath);
                }
            }

            $event->setArgument('frontendPath', $path);
        }
        //return $path;
    }

    public function onAssetThumbnailCreated(GenericEvent $event) {
        if (ENABLE_AZURE) {
            $thumbnail = $event->getSubject();

            $fsPath = $thumbnail->getFileSystemPath();

            if ($fsPath && $event->getArgument("generated")) {
                $cacheKey = "thumb_azure_" . md5($fsPath);

                \Pimcore\Cache::remove($cacheKey);
            }
        }
    }

    public function onFrontEndPathAsset(GenericEvent $event) {
        if (ENABLE_AZURE) {
            $asset = $event->getSubject();
            $path = str_replace(PIMCORE_ASSET_DIRECTORY . "/", $this->azureAssetUrlPrefix . "/", $asset->getFileSystemPath());

            $event->setArgument('frontendPath', $path);
        }

        //return $path;
    }

}
