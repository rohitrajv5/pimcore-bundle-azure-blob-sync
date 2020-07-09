<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace AzurePimcoreBundle\AzureBlobStorage;

/**
 * Represents a simple cache interface.
 */
interface CacheInterface
{
    /**
     * Get a cache item by key.
     *
     * @param string $key Key to retrieve.
     *
     * @return mixed|null Returns the value or null if not found.
     */
    public function get($key);

    /**
     * Set a cache key value.
     *
     * @param string $key   Key to set
     * @param mixed  $value Value to set.
     * @param int    $ttl   Number of seconds the item is allowed to live. Set
     *                      to 0 to allow an unlimited lifetime.
     */
    public function set($key, $value, $ttl = 0);

    /**
     * Remove a cache key.
     *
     * @param string $key Key to remove.
     */
    public function remove($key);
}