<?php

namespace Biigle\FileCache;

use Biigle\FileCache\Contracts\File;
use Biigle\FileCache\Contracts\FileCache as FileCacheContract;
use Exception;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\Adapter\Local;
use League\Flysystem\FileNotFoundException;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * The file cache.
 */
class FileCache implements FileCacheContract
{
    
    /**
     * File cache configuration.
     *
     * @var array
     */
    protected $config;
    
    /**
     * The Filesytem instance to use
     *
     * @var Filesystem
     */
    protected $files;
    
    /**
     * File FilesystemManager instance to use
     *
     * @var FilesystemManager
     */
    protected $storage;
    
    /**
     * Create an instance.
     *
     * @param  array  $config  Optional custom configuration.
     * @param  Filesystem  $files
     * @param  FilesystemManager  $storage
     */
    public function __construct(array $config = [], $files = null, $storage = null)
    {
        $this->config  = array_merge(config('file-cache'), $config);
        $this->files   = $files ?: app('files');
        $this->storage = $storage ?: app('filesystem');
    }
    
    /**
     * {@inheritdoc}
     */
    public function exists(File $file)
    {
        if ($this->isRemote($file)) {
            return $this->existsRemote($file);
        }
        
        return $this->existsDisk($file);
    }
    
    /**
     * {@inheritdoc}
     */
    public function get(File $file, callable $callback, string $ip = null)
    {
        return $this->batch([$file], function ($files, $paths) use ($callback) {
            return call_user_func($callback, $files[0], $paths[0]);
        }, $ip);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getOnce(File $file, callable $callback)
    {
        return $this->batchOnce([$file], function ($files, $paths) use ($callback) {
            return call_user_func($callback, $files[0], $paths[0]);
        });
    }
    
    /**
     * {@inheritdoc}
     */
    public function getStream(File $file)
    {
        $cachedPath = $this->getCachedPath($file);
        
        if ($this->files->exists($cachedPath)) {
            // Update access and modification time to signal that this cached file was
            // used recently.
            touch($cachedPath);
            
            return $this->getFileStream($cachedPath);
        }
        
        if ($this->isRemote($file)) {
            return $this->getFileStream($cachedPath);
        }
        
        $url = explode('://', $file->getUrl());
        
        if ( ! config("filesystems.disks.{$url[0]}")) {
            throw new Exception("Storage disk '{$url[0]}' does not exist.");
        }
        
        try{
            return $this->storage->disk($url[0])->readStream($url[1]);
        }catch (FileNotFoundException $e){
            throw new Exception($e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function batch(array $files, callable $callback, string $ip = null)
    {
        $retrieved = array_map(function ($file) use ($ip) {
            return $this->retrieve($file, $ip);
        }, $files);
        
        $paths = array_map(function ($file) {
            return $file['path'];
        }, $retrieved);
        
        try{
            $result = call_user_func($callback, $files, $paths);
        }finally{
            foreach ($retrieved as $file) {
                fclose($file['handle']);
            }
        }
        
        return $result;
    }
    
    /**
     * {@inheritdoc}
     */
    public function batchOnce(array $files, callable $callback)
    {
        $retrieved = array_map(function ($file) {
            return $this->retrieve($file);
        }, $files);
        
        $paths = array_map(function ($file) {
            return $file['path'];
        }, $retrieved);
        
        try{
            $result = call_user_func($callback, $files, $paths);
        }finally{
            foreach ($retrieved as $index => $file) {
                // Convert to exclusive lock for deletion. Don't delete if lock can't be
                // obtained.
                if (flock($file['handle'], LOCK_EX | LOCK_NB)) {
                    // This path is not the same than $file['path'] for locally stored
                    // files. We don't want to delete locally stored files.
                    $path = $this->getCachedPath($files[$index]);
                    if ($this->files->exists($path)) {
                        $this->files->delete($path);
                    }
                }
                fclose($file['handle']);
            }
        }
        
        return $result;
    }
    
    /**
     * {@inheritdoc}
     */
    public function prune()
    {
        if ( ! $this->files->exists($this->config['path'])) {
            return;
        }
        
        $now = time();
        // Allowed age in seconds.
        $allowedAge = $this->config['max_age'] * 60;
        $totalSize  = 0;
        
        $files = Finder::create()
                       ->files()
                       ->ignoreDotFiles(true)
                       ->in($this->config['path'])
                       ->getIterator();
        
        // Prune files by age.
        foreach ($files as $file) {
            if ($now - $file->getATime() > $allowedAge && $this->delete($file)) {
                continue;
            }
            
            $totalSize += $file->getSize();
        }
        
        $allowedSize = $this->config['max_size'];
        
        // Prune files by cache size.
        if ($totalSize > $allowedSize) {
            $files = Finder::create()
                           ->files()
                           ->ignoreDotFiles(true)
                // This will return the least recently accessed files first.
                           ->sortByAccessedTime()
                           ->in($this->config['path'])
                           ->getIterator();
            
            while ($totalSize > $allowedSize && ($file = $files->current())) {
                $fileSize = $file->getSize();
                if ($this->delete($file)) {
                    $totalSize -= $fileSize;
                }
                $files->next();
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        if ( ! $this->files->exists($this->config['path'])) {
            return;
        }
        
        $files = Finder::create()
                       ->files()
                       ->ignoreDotFiles(true)
                       ->in($this->config['path'])
                       ->getIterator();
        
        foreach ($files as $file) {
            $this->delete($file);
        }
    }
    
    /**
     * Check for existence of a remte file.
     *
     * @param  File  $file
     *
     * @return bool
     */
    protected function existsRemote($file)
    {
        $context = stream_context_create(['http' => ['method' => 'HEAD']]);
        $headers = get_headers($file->getUrl(), 1, $context);
        
        $exists = explode(' ', $headers[0])[1][0] === '2';
        
        if ( ! $exists) {
            return false;
        }
        
        if ( ! empty($this->config['mime_types'])) {
            $type = trim(explode(';', $headers['Content-Type'])[0]);
            if ( ! in_array($type, $this->config['mime_types'])) {
                throw new Exception("MIME type '{$type}' not allowed.");
            }
        }
        
        $maxBytes = intval($this->config['max_file_size']);
        $size     = intval($headers['Content-Length']);
        
        if ($maxBytes >= 0 && $size > $maxBytes) {
            throw new Exception("The file is too large with more than {$maxBytes} bytes.");
        }
        
        return true;
    }
    
    /**
     * Check for existence of a file from a storage disk.
     *
     * @param  File  $file
     *
     * @return bool
     */
    protected function existsDisk($file)
    {
        $url    = explode('://', $file->getUrl());
        $exists = $this->getDisk($file)->exists($url[1]);
        
        if ( ! $exists) {
            return false;
        }
        
        if ( ! empty($this->config['mime_types'])) {
            $type = $this->getDisk($file)->mimeType($url[1]);
            if ( ! in_array($type, $this->config['mime_types'])) {
                throw new Exception("MIME type '{$type}' not allowed.");
            }
        }
        
        $maxBytes = intval($this->config['max_file_size']);
        
        if ($maxBytes >= 0) {
            $size = $this->getDisk($file)->size($url[1]);
            if ($size > $maxBytes) {
                throw new Exception("The file is too large with more than {$maxBytes} bytes.");
            }
        }
        
        return true;
    }
    
    /**
     * Delete a cached file it it is not used.
     *
     * @param  SplFileInfo  $file
     *
     * @return bool If the file has been deleted.
     */
    protected function delete(SplFileInfo $file)
    {
        $handle  = fopen($file->getRealPath(), 'r');
        $deleted = false;
        
        try{
            // Only delete the file if it is not currently used. Else move on.
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->files->delete($file->getRealPath());
                $deleted = true;
            }
        }finally{
            fclose($handle);
        }
        
        return $deleted;
    }
    
    /**
     * Cache a remote or cloud storage file if it is not cached and get the path to
     * the cached file. If the file is local, nothing will be done and the path to the
     * local file will be returned.
     *
     * @param  File  $file  File to get the path for
     *
     * @return array Containing the 'path' to the file and the file 'handle'. Close the
     * handle when finished.
     * @throws Exception If the file could not be cached.
     *
     */
    protected function retrieve(File $file, string $ip = null)
    {
        $this->ensurePathExists();
        $cachedPath = $this->getCachedPath($file);
        
        // This will return false if the file already exists. Else it will create it in
        // read and write mode.
        $handle = @fopen($cachedPath, 'x+');
        
        if ($handle === false) {
            // The file exists, get the file handle in read mode.
            $handle = fopen($cachedPath, 'r');
            // Wait for any LOCK_EX that is set if the file is currently written.
            flock($handle, LOCK_SH);
            
            // Check if the file is still there since the writing operation could have
            // failed. If the file is gone, retry retrieve.
            if (fstat($handle)['nlink'] === 0) {
                fclose($handle);
                
                return $this->retrieve($file);
            }
            
            // The file exists and is no longer written to.
            return $this->retrieveExistingFile($cachedPath, $handle);
        }
        
        // The file did not exist and should be written. Hold LOCK_EX until writing
        // finished.
        flock($handle, LOCK_EX);
        
        try{
            $fileInfo = $this->retrieveNewFile($file, $cachedPath, $handle, $ip);
            // Convert the lock so other workers can use the file from now on.
            flock($handle, LOCK_SH);
        }catch (Exception $e){
            // Remove the empty file if writing failed. This is the case that is caught
            // by 'nlink' === 0 above.
            @unlink($cachedPath);
            fclose($handle);
            throw new Exception("Error while caching file '{$file->getUrl()}': {$e->getMessage()}");
        }
        
        return $fileInfo;
    }
    
    /**
     * Get path and handle for a file that exists in the cache.
     *
     * @param  string  $cachedPath
     * @param  resource  $handle
     *
     * @return array
     */
    protected function retrieveExistingFile($cachedPath, $handle)
    {
        // Update access and modification time to signal that this cached file was
        // used recently.
        touch($cachedPath);
        
        return [
            'path'   => $cachedPath,
            'handle' => $handle,
        ];
    }
    
    /**
     * Get path and handle for a file that does not yet exist in the cache.
     *
     * @param  File  $file
     * @param  string  $cachedPath
     * @param  resource  $handle
     *
     * @return array
     */
    protected function retrieveNewFile(File $file, $cachedPath, $handle, $ip = null)
    {
        if ($this->isRemote($file)) {
            //$this->getRemoteFileWithFopen($file, $handle);
            $this->getRemoteFileWithCurl($file, $handle, $ip);
        } else {
            $newCachedPath = $this->getDiskFile($file, $handle);
            
            // If it is a locally stored file, delete the empty "placeholder"
            // file again. The handle may stay open; it doesn't matter.
            if ($newCachedPath !== $cachedPath) {
                unlink($cachedPath);
            }
            
            $cachedPath = $newCachedPath;
        }
        
        if ( ! empty($this->config['mime_types'])) {
            $type = $this->files->mimeType($cachedPath);
            if ( ! in_array($type, $this->config['mime_types'])) {
                throw new Exception("MIME type '{$type}' not allowed.");
            }
        }
        
        return [
            'path'   => $cachedPath,
            'handle' => $handle,
        ];
    }
    
    /**
     * Cache a remote file and get the path to the cached file.
     *
     * @param  File  $file  Remote file
     * @param  resource  $target  Target file resource
     *
     * @return string
     * @throws Exception If the file could not be cached.
     *
     */
    protected function getRemoteFileWithFopen(File $file, $target)
    {
        $context    = stream_context_create([
            'http' => [
                'timeout' => $this->config['timeout'],
            ],
        ]);
        $source     = $this->getFileStream($file->getUrl(), $context);
        $cachedPath = $this->cacheFromResource($file, $source, $target);
        if (is_resource($source)) {
            fclose($source);
        }
        
        return $cachedPath;
    }
    
    
    protected function getRemoteFileWithCurl(File $file, $handle, string $ip = null)
    {
        $ch = curl_init($file->getUrl());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ( ! is_null($ip)) {
            $host = $this->getUrlHost($file->getUrl());
            $port = $this->getPort($file->getUrl());
            
            curl_setopt($ch, CURLOPT_RESOLVE, [$host.":".$port.":".$ip]);
        }
        
        curl_setopt($ch, CURLOPT_FILE, $handle);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        
        return curl_exec($ch);
    }
    
    protected function getUrlHost(string $url)
    {
        $parsedUrl = parse_url($url);
        
        return $parsedUrl['scheme'].'://'.$parsedUrl['host'];
    }
    
    protected function getPort($url): int
    {
        $parsedUrl = parse_url($url);
        if ($parsedUrl['scheme'] === 'https') {
            return 443;
        }
        
        return 80;
    }
    
    /**
     * Cache an file from a storage disk and get the path to the cached file. Files
     * from local disks are not cached.
     *
     * @param  File  $file  Cloud storage file
     * @param  resource  $target  Target file resource
     *
     * @return string
     * @throws Exception If the file could not be cached.
     *
     */
    protected function getDiskFile(File $file, $target)
    {
        $url     = explode('://', $file->getUrl());
        $disk    = $this->getDisk($file);
        $adapter = $disk->getDriver()->getAdapter();
        
        // Files from the local driver are not cached.
        if ($adapter instanceof Local) {
            if ( ! $disk->exists($url[1])) {
                throw new Exception("File does not exist.");
            }
            
            return $adapter->getPathPrefix().$url[1];
        }
        
        $source     = $disk->readStream($url[1]);
        $cachedPath = $this->cacheFromResource($file, $source, $target);
        if (is_resource($source)) {
            fclose($source);
        }
        
        return $cachedPath;
    }
    
    /**
     * Store the file from the given resource to a cached file.
     *
     * @param  File  $file
     * @param  resource  $source
     * @param  resource  $target
     *
     * @return string Path to the cached file
     * @throws Exception If the file could not be cached.
     *
     */
    protected function cacheFromResource(File $file, $source, $target)
    {
        if ( ! is_resource($source)) {
            throw new Exception('The source resource could not be established.');
        }
        
        $cachedPath = $this->getCachedPath($file);
        $maxBytes   = intval($this->config['max_file_size']);
        $bytes      = stream_copy_to_stream($source, $target, $maxBytes);
        
        if ($bytes === $maxBytes) {
            throw new Exception("The file is too large with more than {$maxBytes} bytes.");
        }
        
        if ($bytes === false) {
            throw new Exception('The source resource is invalid.');
        }
        
        if (stream_get_meta_data($source)['timed_out']) {
            throw new Exception('The source stream timed out while reading data.');
        }
        
        return $cachedPath;
    }
    
    /**
     * Creates the cache directory if it doesn't exist yet.
     */
    protected function ensurePathExists()
    {
        if ( ! $this->files->exists($this->config['path'])) {
            $this->files->makeDirectory($this->config['path'], 0755, true, true);
        }
    }
    
    /**
     * Get the path to the cached file file.
     *
     * @param  File  $file
     *
     * @return string
     */
    protected function getCachedPath(File $file)
    {
        $hash = hash('sha256', $file->getUrl());
        
        return "{$this->config['path']}/{$hash}";
    }
    
    /**
     * Get the stream resource for an file.
     *
     * @param  string  $url
     * @param  resource|null  $context  Stream context
     *
     * @return resource
     */
    protected function getFileStream($url, $context = null)
    {
        // Escape special characters (e.g. spaces) that may occur in parts of a HTTP URL.
        // We do not use urlencode or rawurlencode because they encode some characters
        // (e.g. "+") that should not be changed in the URL.
        if (strpos($url, 'http') === 0) {
            // List of characters to substitute and their replacements at the same index.
            $pattern     = [' '];
            $replacement = ['%20'];
            $url         = str_replace($pattern, $replacement, $url);
        }
        
        if (is_resource($context)) {
            return @fopen($url, 'r', false, $context);
        }
        
        return @fopen($url, 'r');
    }
    
    /**
     * Determine if an file is remote, i.e. served by a public webserver.
     *
     * @param  File  $file
     *
     * @return boolean
     */
    protected function isRemote(File $file)
    {
        return strpos($file->getUrl(), 'http') === 0;
    }
    
    /**
     * Get the storage disk on which a file is stored.
     *
     * @param  File  $file
     *
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected function getDisk(File $file)
    {
        $url = explode('://', $file->getUrl());
        
        if ( ! config("filesystems.disks.{$url[0]}")) {
            throw new Exception("Storage disk '{$url[0]}' does not exist.");
        }
        
        return $this->storage->disk($url[0]);
    }
}
