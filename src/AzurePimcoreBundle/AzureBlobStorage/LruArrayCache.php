<?php

namespace AzurePimcoreBundle\AzureBlobStorage;

class LruArrayCache implements CacheInterface, \Countable
{
    /** @var int */
    private $maxItems;

    /** @var array */
    private $items = array();

    /**
     * @param int $maxItems Maximum number of allowed cache items.
     */
    public function __construct($maxItems = 1000)
    {
        $this->maxItems = $maxItems;
    }

    public function get($key)
    {
        if (!isset($this->items[$key])) {
            return null;
        }

        $entry = $this->items[$key];

        // Ensure the item is not expired.
        if (!$entry[1] || time() < $entry[1]) {
            // LRU: remove the item and push it to the end of the array.
            unset($this->items[$key]);
            $this->items[$key] = $entry;
            return $entry[0];
        }

        unset($this->items[$key]);
        return null;
    }

    public function set($key, $value, $ttl = 0)
    {
        // Only call time() if the TTL is not 0/false/null
        $ttl = $ttl ? time() + $ttl : 0;
        $this->items[$key] = [$value, $ttl];

        // Determine if there are more items in the cache than allowed.
        $diff = count($this->items) - $this->maxItems;

        // Clear out least recently used items.
        if ($diff > 0) {
            // Reset to the beginning of the array and begin unsetting.
            reset($this->items);
            for ($i = 0; $i < $diff; $i++) {
                unset($this->items[key($this->items)]);
                next($this->items);
            }
        }
    }

    public function remove($key)
    {
        unset($this->items[$key]);
    }

    public function count()
    {
        return count($this->items);
    }
}
