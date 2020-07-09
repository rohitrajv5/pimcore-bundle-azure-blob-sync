<?php

namespace AzurePimcoreBundle\AzureBlobStorage;

use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureBlobClient extends BlobRestProxy {
    
    protected $blobCLient;
    
    public function __construct(BlobRestProxy $blobClient) {
        $this->blobCLient = $blobClient;
    }

    /**
     * Stream wrapper clients
     *
     * @var array
     */
    protected static $wrapperClients = array();
    
    public function getBlobCLient() {
        return $this->blobCLient;
    }

    /**
     * Register this object as stream wrapper client
     *
     * @param  string $name Protocol name
     * @return Blob
     */
    public function registerAsClient($name) {
        self::$wrapperClients[$name] = $this->blobCLient;
        return $this->blobCLient;
    }

    /**
     * Unregister this object as stream wrapper client
     *
     * @param  string $name Protocol name
     * @return Blob
     */
    public function unregisterAsClient($name) {
        unset(self::$wrapperClients[$name]);
        return $this->blobCLient;
    }

    /**
     * Get wrapper client for stream type
     *
     * @param  string $name Protocol name
     * @return Blob
     */
    public static function getWrapperClient($name) {
        return self::$wrapperClients[$name];
    }

    /**
     * Register this object as stream wrapper
     *
     * @param  string $name Protocol name
     */
    public function registerStreamWrapper($name = 'azure') {
        stream_register_wrapper($name, __NAMESPACE__ . '\\Stream');
        $this->registerAsClient($name);
    }

    /**
     * Unregister this object as stream wrapper
     *
     * @param  string $name Protocol name
     * @return Blob
     */
    public function unregisterStreamWrapper($name = 'azure') {
        stream_wrapper_unregister($name);
        $this->unregisterAsClient($name);
    }

}
