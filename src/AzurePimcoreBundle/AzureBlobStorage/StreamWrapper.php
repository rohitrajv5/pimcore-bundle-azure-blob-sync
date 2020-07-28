<?php

namespace AzurePimcoreBundle\AzureBlobStorage;

use AzurePimcoreBundle\AzureBlobStorage\CacheInterface;
use AzurePimcoreBundle\AzureBlobStorage\LruArrayCache;
use AzurePimcoreBundle\AzureBlobStorage\BlobException;
use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\CachingStream;
use Psr\Http\Message\StreamInterface;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class StreamWrapper {

    /** @var resource|null Stream context (this is set by PHP) */
    public $context;

    /** @var StreamInterface Underlying stream resource */
    private $body;

    /** @var int Size of the body that is opened */
    private $size;

    /** @var array Hash of opened stream parameters */
    private $params = [];

    /** @var string Mode in which the stream was opened */
    private $mode;

    /** @var \Iterator Iterator used with opendir() related calls */
    private $objectIterator;

    /** @var string The bucket that was opened when opendir() was called */
    private $openedBucket;

    /** @var string The prefix of the bucket that was opened with opendir() */
    private $openedBucketPrefix;

    /** @var string Opened bucket path */
    private $openedPath;

    /** @var CacheInterface Cache for object and dir lookups */
    private $cache;

    /** @var string The opened protocol (e.g., "azure") */
    private $protocol = 'blob';
    private $blobsArray = [];
    private $tmpFile = '';
    private $nextMarker = null;

    /**
     * Register the 'blob://' stream wrapper
     *
     * @param BlobRestProxy $client   Client to use with the stream wrapper
     * @param string            $protocol Protocol to register as.
     * @param CacheInterface    $cache    Default cache for the protocol.
     */
    public static function register(
            BlobRestProxy $client, $protocol = 'blob', CacheInterface $cache = null
    ) {
        if (in_array($protocol, stream_get_wrappers())) {
            stream_wrapper_unregister($protocol);
        }

        // Set the client passed in as the default stream context client
        stream_wrapper_register($protocol, get_called_class(), STREAM_IS_URL);
        $default = stream_context_get_options(stream_context_get_default());
        $default[$protocol]['client'] = $client;

        if ($cache) {
            $default[$protocol]['cache'] = $cache;
        } elseif (!isset($default[$protocol]['cache'])) {
            // Set a default cache adapter.
            $default[$protocol]['cache'] = new LruArrayCache();
        }

        stream_context_set_default($default);
    }

    public function stream_close() {
        $this->body = $this->cache = null;

        if (is_file($this->tmpFile))
            unlink($this->tmpFile);
    }

    public function stream_open($path, $mode, $options, &$opened_path) {
        $mode = rtrim($mode, '+');
        $this->initProtocol($path);
        $this->params = $this->getBucketKey($path);
        $this->mode = rtrim($mode, 'bt');

        if ($errors = $this->validate($path, $this->mode)) {
            return $this->triggerError($errors);
        }
        return $this->boolCall(function() use ($path) {
                    switch ($this->mode) {
                        case 'r': return $this->openReadStream($path);
                        case 'a': return $this->openAppendStream($path);
                        default: return $this->openWriteStream($path);
                    }
                });
    }

    public function stream_eof() {
        return $this->body->eof();
    }

    public function stream_flush() {
        if ($this->mode == 'r') {
            return false;
        }

        if ($this->body->isSeekable()) {
            $this->body->seek(0);
        }
        $params = $this->getOptions(true);
        $params['Body'] = $this->body;

        // Attempt to guess the ContentType of the upload based on the
        // file extension of the key
        if (!isset($params['ContentType']) &&
                ($type = Psr7\mimetype_from_filename($params['Key']))
        ) {
            $params['ContentType'] = $type;
        }

        $options = new \MicrosoftAzure\Storage\Blob\Models\CreateBlockBlobOptions();
        $options->setContentType($params['ContentType']);

        $this->clearCacheKey("blob://{$params['Bucket']}/{$params['Key']}");
        return $this->boolCall(function () use ($params, $options) {
                    return (bool) $this->getClient()->createBlockBlob($params['Bucket'], $params['Key'], $params['Body'], $options);
                });
    }

    public function stream_read($count) {
        return $this->body->read($count);
    }

    public function stream_seek($offset, $whence = SEEK_SET) {
        return !$this->body->isSeekable() ? false : $this->boolCall(function () use ($offset, $whence) {
                    $this->body->seek($offset, $whence);
                    return true;
                });
    }

    public function stream_tell() {
        return $this->boolCall(function() {
                    return $this->body->tell();
                });
    }

    public function stream_write($data) {
        return $this->body->write($data);
    }

    public function unlink($path) {
        $this->initProtocol($path);

        return $this->boolCall(function () use ($path) {
                    $this->clearCacheKey($path);
                    $params = $this->withPath($path);
                    $this->getClient()->deleteBlob($params['Bucket'], $params['Key']);
                    return true;
                });
    }

    public function stream_stat() {
        $stat = $this->getStatTemplate();
        $stat[7] = $stat['size'] = $this->getSize();
        $stat[2] = $stat['mode'] = $this->mode;

        return $stat;
    }

    /**
     * Provides information for is_dir, is_file, filesize, etc. Works on
     * buckets, keys, and prefixes.
     * @link http://www.php.net/manual/en/streamwrapper.url-stat.php
     */
    public function url_stat($path, $flags) {
        $this->initProtocol($path);
        // Some paths come through as blob:// for some reason.
        $split = explode('://', $path);
        $path = strtolower($split[0]) . '://' . $split[1];

        // Check if this path is in the url_stat cache
        if ($value = $this->getCacheStorage()->get($path)) {
            return $value;
        }

        $stat = $this->createStat($path, $flags);
        if (is_array($stat)) {
            $this->getCacheStorage()->set($path, $stat);
        }

        return $stat;
    }

    /**
     * Parse the protocol out of the given path.
     *
     * @param $path
     */
    private function initProtocol($path) {
        $parts = explode('://', $path, 2);
        $this->protocol = $parts[0] ?: 'blob';
    }

    private function createStat($path, $flags) {
        $this->initProtocol($path);
        $parts = $this->withPath($path);
        if (!$parts['Key']) {
            return $this->statDirectory($parts, $path, $flags);
        }

        $listBlobOptions = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
        $listBlobOptions->setPrefix($parts['Key']);
        $listBlobOptions->setMaxResults(1);
        $listBlobResult = $this->getClient()->listBlobs($parts['Bucket'], $listBlobOptions);

        $blobs = $listBlobResult->getBlobs();
        if ($blobs) {
            $key = $blobs[0]->getName();
            if ($parts['Key'] != $key) {
                return $this->statDirectory($parts, $path, $flags);
            }
        }
        return $this->boolCall(function () use ($parts, $path) {
                    try {
//                $result = $this->getClient()->headObject($parts);
                        $result = $this->getClient()->getBlobProperties($parts['Bucket'], $parts['Key'])->getProperties();
                        if (substr($parts['Key'], -1, 1) == '/' &&
                                $result->getContentLength() == 0
                        ) {
                            // Return as if it is a bucket to account for console
                            // bucket objects (e.g., zero-byte object "foo/")
                            return $this->formatUrlStat($path);
                        }

                        // Attempt to stat and cache regular object
                        return $this->formatUrlStat($result);
                    } catch (BlobException $e) {
                        // Maybe this isn't an actual key, but a prefix. Do a prefix
                        // listing of objects to determine.

                        $listBlobOptions = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
                        $listBlobOptions->setPrefix((rtrim($parts['Key'], '/') . '/'));
                        $listBlobResult = $this->getClient()->listBlobs($parts['Bucket'], $listBlobOptions);

                        $blobs = $listBlobResult->getBlobs();
                        if (!$blobs) {
                            throw new \Exception("File or directory not found: $path");
                        }
                        return $this->formatUrlStat($path);
                    }
                }, $flags);
    }

    private function statDirectory($parts, $path, $flags) {
        // Stat "directories": buckets, or "blob://"
        if (!$parts['Bucket'] ||
                $this->containerExists($this->getClient(), $parts['Bucket'])
        ) {
            return $this->formatUrlStat($path);
        }

        return $this->triggerError("File or directory not found: $path", $flags);
    }

    private function containerExists(BlobRestProxy $client, $container) {
        $listContainersOptions = new \MicrosoftAzure\Storage\Blob\Models\ListContainersOptions();
        $listContainersOptions->setPrefix($container);
        $listContainersResult = $client->listContainers($listContainersOptions);
        $containerExists = false;
        $containers = $listContainersResult->getContainers();
        foreach ($containers as $containerObj) {
            if ($containerObj->getName() == $container) {
                // The container exists.
                $containerExists = true;
                // No need to keep checking.
                break;
            }
        }

        return $containerExists;
    }

    /**
     * Support for mkdir().
     *
     * @param string $path    Directory which should be created.
     * @param int    $mode    Permissions. 700-range permissions map to
     *                        ACL_PUBLIC. 600-range permissions map to
     *                        ACL_AUTH_READ. All other permissions map to
     *                        ACL_PRIVATE. Expects octal form.
     * @param int    $options A bitwise mask of values, such as
     *                        STREAM_MKDIR_RECURSIVE.
     *
     * @return bool
     * @link http://www.php.net/manual/en/streamwrapper.mkdir.php
     */
    public function mkdir($path, $mode, $options) {
        $this->initProtocol($path);
        $params = $this->withPath($path);
        $this->clearCacheKey($path);
        if (!$params['Bucket']) {
            return false;
        }

        if (!isset($params['ACL'])) {
            $params['ACL'] = $this->determineAcl($mode);
        }

        return empty($params['Key']) ? $this->createBucket($path, $params) : $this->createSubfolder($path, $params);
    }

    public function rmdir($path, $options) {
        $this->initProtocol($path);
        $this->clearCacheKey($path);
        $params = $this->withPath($path);
        $client = $this->getClient();

        if (!$params['Bucket']) {
            return $this->triggerError('You must specify a bucket');
        }

        return $this->boolCall(function () use ($params, $path, $client) {
                    if (!$params['Key']) {
                        $client->deleteContainer($params['Bucket']);
                        return true;
                    }
                    return $this->deleteSubfolder($path, $params);
                });
    }

    /**
     * Support for opendir().
     *
     * The opendir() method of the Amazon S3 stream wrapper supports a stream
     * context option of "listFilter". listFilter must be a callable that
     * accepts an associative array of object data and returns true if the
     * object should be yielded when iterating the keys in a bucket.
     *
     * @param string $path    The path to the directory
     *                        (e.g. "blob://dir[</prefix>]")
     * @param string $options Unused option variable
     *
     * @return bool true on success
     * @see http://www.php.net/manual/en/function.opendir.php
     */
    public function dir_opendir($path, $options) {
        $this->initProtocol($path);
        $this->openedPath = $path;
        $params = $this->withPath($path);
        $delimiter = $this->getOption('delimiter');
        /** @var callable $filterFn */
        $filterFn = $this->getOption('listFilter');
        $op = ['Bucket' => $params['Bucket']];
        $this->openedBucket = $params['Bucket'];

        if ($delimiter === null) {
            $delimiter = '/';
        }

        if ($delimiter) {
            $op['Delimiter'] = $delimiter;
        }

        if ($params['Key']) {
            $params['Key'] = rtrim($params['Key'], $delimiter) . $delimiter;
            $op['Prefix'] = $params['Key'];
        }

        $this->openedBucketPrefix = $params['Key'];

        // Filter our "/" keys added by the console as directories, and ensure
        // that if a filter function is provided that it passes the filter.
//        $listBlobOptions = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
//        $listBlobOptions->setPrefix((rtrim($params['Key'], '/') . '/'));
//        $listBlobOptions->setMaxResults(2);
//        
//        if($this->nextMarker){
//            $listBlobOptions->setMarker($this->nextMarker);
//        }
//        
//        $listBlobResult = $this->getClient()->listBlobs($params['Bucket'], $listBlobOptions);
//
//        $this->blobsArray = (array) $listBlobResult->getBlobs();
//        $this->nextMarker = $listBlobResult->getNextMarker();

        $this->getBlobsArray($params['Bucket'], $params['Key']);
        return true;
    }

    private function getBlobsArray($bucket, $prefix) {
        $listBlobOptions = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
        $listBlobOptions->setPrefix($prefix);
        $listBlobOptions->setMaxResults(500);

        if ($this->nextMarker) {
            $listBlobOptions->setMarker($this->nextMarker);
        }

        $listBlobResult = $this->getClient()->listBlobs($bucket, $listBlobOptions);
        
        $this->nextMarker = $listBlobResult->getNextMarker();
        
        $blobs = (array) $listBlobResult->getBlobs();
        $blobsArray = [];
        foreach($blobs as $blob){
            $contentLength = $blob->getProperties()->getContentLength();
            if($contentLength > 0){
                $blobsArray[] = $blob;
            }
        }
        $this->blobsArray = $blobsArray;
    }

    /**
     * Close the directory listing handles
     *
     * @return bool true on success
     */
    public function dir_closedir() {
        $this->blobsArray = null;
        gc_collect_cycles();

        return true;
    }

    /**
     * This method is called in response to rewinddir()
     *
     * @return boolean true on success
     */
    public function dir_rewinddir() {
        $this->boolCall(function() {
            reset($this->blobsArray);
            return true;
        });
    }

    /**
     * This method is called in response to readdir()
     *
     * @return string Should return a string representing the next filename, or
     *                false if there is no next file.
     * @link http://www.php.net/manual/en/function.readdir.php
     */
    public function dir_readdir() {
        $object = current($this->blobsArray);
        if ($object !== false) {
            next($this->blobsArray);
            $name = $object->getName();

            return $this->openedBucketPrefix ? substr($name, strlen($this->openedBucketPrefix)) : $name;
        } else if ($this->nextMarker) {
            //reset($this->blobsArray);
            $this->getBlobsArray($this->openedBucket, $this->openedBucketPrefix);
            $object = current($this->blobsArray);
            if ($object !== false) {
                next($this->blobsArray);
                $name = $object->getName();

                return $this->openedBucketPrefix ? substr($name, strlen($this->openedBucketPrefix)) : $name;
            }
        }

        return false;
    }

    private function formatKey($key) {
        $protocol = explode('://', $this->openedPath)[0];
        return "{$protocol}://{$this->openedBucket}/{$key}";
    }

    /**
     * Called in response to rename() to rename a file or directory. Currently
     * only supports renaming objects.
     *
     * @param string $path_from the path to the file to rename
     * @param string $path_to   the new path to the file
     *
     * @return bool true if file was successfully renamed
     * @link http://www.php.net/manual/en/function.rename.php
     */
    public function rename($path_from, $path_to) {
        // PHP will not allow rename across wrapper types, so we can safely
        // assume $path_from and $path_to have the same protocol
        $this->initProtocol($path_from);
        $partsFrom = $this->withPath($path_from);
        $partsTo = $this->withPath($path_to);
        $this->clearCacheKey($path_from);
        $this->clearCacheKey($path_to);

        if (!$partsFrom['Key'] || !$partsTo['Key']) {
            return $this->triggerError('The Azure stream wrapper only '
                            . 'supports copying objects');
        }

        $options = new \MicrosoftAzure\Storage\Blob\Models\CopyBlobOptions();

        return $this->boolCall(function () use ($partsFrom, $partsTo, $options) {
                    //$options = $this->getOptions(true);
                    // Copy the object and allow overriding default parameters if
                    // desired, but by default copy metadata
                    $this->getClient()->copyBlob(
                            $partsTo['Bucket'], $partsTo['Key'], $partsFrom['Bucket'], $partsFrom['Key'], $options
                    );
                    // Delete the original object                                        
                    $this->getClient()->deleteBlob($partsFrom['Bucket'], $partsFrom['Key']);
                    return true;
                });
    }

    public function stream_cast($cast_as) {
        return false;
    }

    /**
     * Validates the provided stream arguments for fopen and returns an array
     * of errors.
     */
    private function validate($path, $mode) {
        $errors = [];

        if (!$this->getOption('Key')) {
            $errors[] = 'Cannot open a bucket. You must specify a path in the '
                    . 'form of blob://container/key';
        }

        if (!in_array($mode, ['r', 'w', 'a', 'x'])) {
            $errors[] = "Mode not supported: {$mode}. "
                    . "Use one 'r', 'w', 'a', or 'x'.";
        }

        // When using mode "x" validate if the file exists before attempting
        // to read
        if ($mode == 'x' &&
                $this->blobExists($this->getClient(), $this->getOption('Bucket'), $this->getOption('Key'), $this->getOptions(true)
                )
        ) {
            $errors[] = "{$path} already exists on Azure Blob";
        }

        return $errors;
    }

    /**
     * Get the stream context options available to the current stream
     *
     * @param bool $removeContextData Set to true to remove contextual kvp's
     *                                like 'client' from the result.
     *
     * @return array
     */
    private function getOptions($removeContextData = false) {
        // Context is not set when doing things like stat
        if ($this->context === null) {
            $options = [];
        } else {
            $options = stream_context_get_options($this->context);
            $options = isset($options[$this->protocol]) ? $options[$this->protocol] : [];
        }

        $default = stream_context_get_options(stream_context_get_default());
        $default = isset($default[$this->protocol]) ? $default[$this->protocol] : [];
        $result = $this->params + $options + $default;

        if ($removeContextData) {
            unset($result['client'], $result['seekable'], $result['cache']);
        }

        return $result;
    }

    /**
     * Get a specific stream context option
     *
     * @param string $name Name of the option to retrieve
     *
     * @return mixed|null
     */
    private function getOption($name) {
        $options = $this->getOptions();

        return isset($options[$name]) ? $options[$name] : null;
    }

    /**
     * Gets the client from the stream context
     *
     * @return BlobRestProxy
     * @throws \RuntimeException if no client has been configured
     */
    private function getClient() {
        if (!$client = $this->getOption('client')) {
            throw new \RuntimeException('No client in stream context');
        }

        return $client;
    }

    private function getBucketKey($path) {
        // Remove the protocol
        $parts = explode('://', $path);
        // Get the bucket, key
        $parts = explode('/', $parts[1], 2);

        return [
            'Bucket' => $parts[0],
            'Key' => isset($parts[1]) ? $parts[1] : null
        ];
    }

    /**
     * Get the bucket and key from the passed path (e.g. blob://bucket/key)
     *
     * @param string $path Path passed to the stream wrapper
     *
     * @return array Hash of 'Bucket', 'Key', and custom params from the context
     */
    private function withPath($path) {
        $params = $this->getOptions(true);

        return $this->getBucketKey($path) + $params;
    }

    private function openReadStream() {
        $client = $this->getClient();

        $result = $client->getBlob($this->getOption('Bucket'), $this->getOption('Key'));

        $this->size = $result->getProperties()->getContentLength();
        $this->body = new Stream($result->getContentStream());

        // Wrap the body in a caching entity body if seeking is allowed
        if ($this->getOption('seekable') && !$this->body->isSeekable()) {
            $this->body = new CachingStream($this->body);
        }

        return true;
    }

    private function openWriteStream() {
        $this->body = new Stream(fopen('php://temp', 'r+'));
//        $this->tmpFile = PIMCORE_SYSTEM_TEMP_DIRECTORY . '/blob-create-tmp-file-' . time() . uniqid();
//        touch($this->tmpFile);
//        $this->body = new Stream(fopen($this->tmpFile, 'r+'));
        return true;
    }

    private function openAppendStream() {
        try {
            // Get the body of the object and seek to the end of the stream
            $client = $this->getClient();

            $result = $client->getBlob($this->getOption('Bucket'), $this->getOption('Key'));
            $this->body = new Stream($result->getContentStream());
            $this->body->seek(0, SEEK_END);
            return true;
        } catch (BlobException $e) {
            // The object does not exist, so use a simple write stream
            return $this->openWriteStream();
        }
    }

    /**
     * Trigger one or more errors
     *
     * @param string|array $errors Errors to trigger
     * @param mixed        $flags  If set to STREAM_URL_STAT_QUIET, then no
     *                             error or exception occurs
     *
     * @return bool Returns false
     * @throws \RuntimeException if throw_errors is true
     */
    private function triggerError($errors, $flags = null) {
        // This is triggered with things like file_exists()
        if ($flags & STREAM_URL_STAT_QUIET) {
            return $flags & STREAM_URL_STAT_LINK
                    // This is triggered for things like is_link()
                    ? $this->formatUrlStat(false) : false;
        }

        // This is triggered when doing things like lstat() or stat()
        \Pimcore\Log\Simple::log('blob_log_1', json_encode(array('error' => implode("\n", (array) $errors), "time" => date("M,d,Y h:i:s A"))));
        trigger_error(implode("\n", (array) $errors), E_USER_WARNING);

        return false;
    }

    /**
     * Prepare a url_stat result array
     *
     * @param string|array $result Data to add
     *
     * @return array Returns the modified url_stat result
     */
    private function formatUrlStat($result = null) {
        $stat = $this->getStatTemplate();
        switch (gettype($result)) {
            case 'NULL':
                $stat['mode'] = $stat[2] = 0040777;
            case 'string':
                // Directory with 0777 access - see "man 2 stat".
                $stat['mode'] = $stat[2] = 0040777;
                break;
            case 'array':
                // Regular file with 0777 access - see "man 2 stat".
                $stat['mode'] = $stat[2] = 0100777;
                // Pluck the content-length if available.
                if (isset($result['ContentLength'])) {
                    $stat['size'] = $stat[7] = $result['ContentLength'];
                } elseif (isset($result['Size'])) {
                    $stat['size'] = $stat[7] = $result['Size'];
                }
                if (isset($result['LastModified'])) {
                    // ListObjects or HeadObject result
                    $stat['mtime'] = $stat[9] = $stat['ctime'] = $stat[10] = strtotime($result['LastModified']);
                }
                break;
            case 'object':
                $stat['mode'] = $stat[2] = 0100777;
                $stat['size'] = $stat[7] = $result->getContentLength();
                $stat['mtime'] = $stat[9] = $stat['ctime'] = $stat[10] = $result->getLastModified()->getTimestamp();
        }

        return $stat;
    }

    /**
     * Creates a bucket for the given parameters.
     *
     * @param string $path   Stream wrapper path
     * @param array  $params A result of StreamWrapper::withPath()
     *
     * @return bool Returns true on success or false on failure
     */
    private function createBucket($path, array $params) {
        if ($this->containerExists($this->getClient(), $params['Bucket'])) {
            return $this->triggerError("Bucket already exists: {$path}");
        }

        return $this->boolCall(function () use ($params, $path) {
                    $this->getClient()->createContainer($params['Bucket']);
                    $this->clearCacheKey($path);
                    return true;
                });
    }

    /**
     * Creates a pseudo-folder by creating an empty "/" suffixed key
     *
     * @param string $path   Stream wrapper path
     * @param array  $params A result of StreamWrapper::withPath()
     *
     * @return bool
     */
    private function createSubfolder($path, array $params) {
        // Ensure the path ends in "/" and the body is empty.
        $params['Key'] = rtrim($params['Key'], '/') . '/';
        $params['Body'] = '';

        // Fail if this pseudo directory key already exists



        if ($this->blobExists($this->getClient(), $params['Bucket'], $params['Key'])
        ) {
            return true; //$this->triggerError("Subfolder already exists: {$path}");
        }

        return $this->boolCall(function () use ($params, $path) {
                    $this->getClient()->createBlockBlob($params['Bucket'], $params['Key'], $params['Body']);
                    $this->clearCacheKey($path);
                    return true;
                });
    }

    private function blobExists(BlobRestProxy $client, $container, $key, $options = []) {
        $listBlobOptions = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
        $listBlobOptions->setPrefix($key);
        $listBlobOptions->setMaxResults(1);
        $listBlobResult = $client->listBlobs($container, $listBlobOptions);

        $blobExists = false;
        $blobs = $listBlobResult->getBlobs();
        foreach ($blobs as $blob) {
            if ($blob->getName() == $key) {
                // The container exists.
                $blobExists = true;
                // No need to keep checking.
                break;
            }
        }

        return $blobExists;
    }

    /**
     * Deletes a nested subfolder if it is empty.
     *
     * @param string $path   Path that is being deleted (e.g., 'blob://a/b/c')
     * @param array  $params A result of StreamWrapper::withPath()
     *
     * @return bool
     */
    private function deleteSubfolder($path, $params) {
        // Use a key that adds a trailing slash if needed.
        $prefix = rtrim($params['Key'], '/') . '/';
        $listBlobOptions = new \MicrosoftAzure\Storage\Blob\Models\ListBlobsOptions();
        $listBlobOptions->setPrefix(($prefix));
        $listBlobOptions->setMaxResults(1);
        $listBlobResult = $this->getClient()->listBlobs($params['Bucket'], $listBlobOptions);

        $blobs = $listBlobResult->getBlobs();

        // Check if the bucket contains keys other than the placeholder
        if ($blobs) {
            return (count($blobs) > 1 || $blobs[0]->getName() != $prefix) ? $this->deleteFolder($params['Bucket'], $blobs[0]->getName(), $prefix) : $this->unlink(rtrim($path, '/') . '/');
        }
        return true;
    }
    
    private function deleteFolder($bucket, $blob, $prefix = '') {
        if(strlen($prefix) > strlen($blob)){
            return true;
        }
        
        $this->getClient()->deleteBlob($bucket, $blob);
        return true;        
    }

    /**
     * Determine the most appropriate ACL based on a file mode.
     *
     * @param int $mode File mode
     *
     * @return string
     */
    private function determineAcl($mode) {
        switch (substr(decoct($mode), 0, 1)) {
            case '7': return 'public-read';
            case '6': return 'authenticated-read';
            default: return 'private';
        }
    }

    /**
     * Gets a URL stat template with default values
     *
     * @return array
     */
    private function getStatTemplate() {
        return [
            0 => 0, 'dev' => 0,
            1 => 0, 'ino' => 0,
            2 => 0, 'mode' => 0,
            3 => 0, 'nlink' => 0,
            4 => 0, 'uid' => 0,
            5 => 0, 'gid' => 0,
            6 => -1, 'rdev' => -1,
            7 => 0, 'size' => 0,
            8 => 0, 'atime' => 0,
            9 => 0, 'mtime' => 0,
            10 => 0, 'ctime' => 0,
            11 => -1, 'blksize' => -1,
            12 => -1, 'blocks' => -1,
        ];
    }

    /**
     * Invokes a callable and triggers an error if an exception occurs while
     * calling the function.
     *
     * @param callable $fn
     * @param int      $flags
     *
     * @return bool
     */
    private function boolCall(callable $fn, $flags = null) {
        try {
            return $fn();
        } catch (\Exception $e) {
            return $this->triggerError($e->getMessage(), $flags);
        }
    }

    /**
     * @return LruArrayCache
     */
    private function getCacheStorage() {
        if (!$this->cache) {
            $this->cache = $this->getOption('cache') ?: new LruArrayCache();
        }

        return $this->cache;
    }

    /**
     * Clears a specific stat cache value from the stat cache and LRU cache.
     *
     * @param string $key blob path (blob://bucket/key).
     */
    private function clearCacheKey($key) {
        clearstatcache(true, $key);
        $this->getCacheStorage()->remove($key);
    }

    /**
     * Returns the size of the opened object body.
     *
     * @return int|null
     */
    private function getSize() {
        $size = $this->body->getSize();

        return $size !== null ? $size : $this->size;
    }

}
