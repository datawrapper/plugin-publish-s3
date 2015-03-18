<?php

class MockS3 {
    protected $root;

    public function __construct($root) {
        $this->root = realpath($root);
    }

    public function putObjectFile($file, $bucket, $uri, $acl = S3::ACL_PRIVATE, $metaHeaders = array(), $contentType = null) {
        $root = $this->root.'/'.$bucket.'/'.dirname(ltrim($uri, '/'));

        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        copy($file, $root.'/'.basename($uri));
        file_put_contents($root.'/'.basename($uri).'.meta', json_encode(array(
            'acl'          => $acl,
            'headers'      => $metaHeaders,
            'content-type' => $contentType
        )));
    }

    public function deleteObject($bucket, $uri) {
        $path = $this->root.'/'.$bucket.'/'.ltrim($uri, '/');

        if (!is_file($path)) {
            unlink($path);
            return true;
        }

        return false;
    }
}
