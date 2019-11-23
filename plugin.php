<?php
/**
 * Datawrapper Publish S3
 */

use Aws\S3\S3Client;

class DatawrapperPlugin_PublishS3 extends DatawrapperPlugin {
    public function init() {
        $cfg = $this->getConfig();
        $plugin = $this;

        if (empty($cfg['accesskey'])) return;

        $s3config = [
            'version' => '2006-03-01',
            'credentials' => [
                'key' => $cfg['accesskey'],
                'secret' => $cfg['secretkey']
            ]
        ];
        if (!empty($cfg['region'])) $s3config['region'] = $cfg['region'];
        else if (!empty($cfg['endpoint'])) $s3config['endpoint'] = $cfg['endpoint'];

        $this->S3 = new S3Client($s3config);

        if ($cfg) {
            DatawrapperHooks::register(DatawrapperHooks::PUBLISH_FILES, array($this, 'publish'));
            DatawrapperHooks::register(DatawrapperHooks::UNPUBLISH_FILES, array($this, 'unpublish'));
            DatawrapperHooks::register(DatawrapperHooks::GET_PUBLISHED_URL, array($this, 'getUrl'));
            DatawrapperHooks::register(DatawrapperHooks::GET_PUBLISH_STORAGE_KEY, array($this, 'getBucketName'));

            DatawrapperHooks::register(DatawrapperHooks::PROVIDE_API, function ($app) use ($plugin) {
                return array(
                    'url' => 'publish-s3/embed-code/:chartId',
                    'method' => 'GET',
                    'action' => function ($chartId) use ($app, $plugin) {
                        $app->response()->header('Content-Type', 'application/json');

                        $chart = ChartQuery::create()->findPk($chartId);
                        if (!$chart) return;
                        $embedCodes = $chart->getMetadata('publish.embed-codes');
                        if (empty($embedCodes)) { $embedCodes = [];}
                        print (json_encode($embedCodes));
                    }
                );
            });

            DatawrapperHooks::register(DatawrapperHooks::GET_CHART_ACTIONS, function($chart) {
                return array(
                    'id'     => 'publish-s3',
                    'icon'   => 'cloud-upload',
                    'title'  => __('publish / button'),
                    'order'  => 100,
                    'banner' => array(
                        'text'  => __('publish / button / banner'),
                        'style' => ''
                    )
                );
            });


            if (class_exists('DatawrapperPlugin_Oembed')) {
                DatawrapperHooks::register(DatawrapperPlugin_Oembed::GET_PUBLISHED_URL_PATTERN, array($this, 'getUrlPattern'));
            }
        }
    }

    /**
     * pushs a list of files to S3
     *
     * @param files list of file descriptions in the format [localFile, remoteFile, contentType]
     * e.g.
     *
     * array(
     *     array('path/t/olocal/file', 'remote/file', 'text/plain')
     * )
     */
    public function publish($files) {
        $cfg = $this->getConfig();

        foreach ($files as $info) {
            $header = array();

            $putObjectCfg = [
                'ACL' => 'public-read',
                'Bucket' => $cfg['bucket'],
                'Key' => $info[1],
                'SourceFile' => $info[0]
            ];

            if (count($info) > 2) {
                $putObjectCfg['ContentType'] = $info[2];
            }

            if (isset($cfg['cache-control'])) {
                $putObjectCfg['CacheControl'] = $cfg['cache-control'];
            }

            try {
                $result = $this->S3->putObject($putObjectCfg);
            } catch (Exception $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
        }
    }

    /**
     * Removes a list of files from S3
     *
     * @param files  list of remote file names (removeFile)
     */
    public function unpublish($files) {
        $cfg = $this->getConfig();

        foreach ($files as $file) {
            try {
                $result = $this->S3->deleteObject([
                    'Bucket' => $cfg['bucket'],
                    'Key' => $file,
                ]);
            } catch (Exception $e) {
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
        }
    }


    /**
     * Returns URL to the chart hosted on S3
     *
     * @param chart Chart class
     */
    public function getUrl($chart) {
        $cfg = $this->getConfig();
        if (!empty($cfg['alias'])) {
            return $cfg['alias'] . '/' . $chart->getID() . '/' . $chart->getPublicVersion() . '/';
        }
        return '//' . $cfg['bucket'] . '.s3.amazonaws.com/' . $chart->getID() . '/' . $chart->getPublicVersion() . '/index.html';
    }

    /**
     * Returns a regular expression that can match the URLs of charts published
     * on S3
     */
    public function getUrlPattern() {
        $cfg = $this->getConfig();

        if (!empty($cfg['alias-ssl'])) {
            return preg_quote($cfg['alias-ssl'], '/') . '\/(?<id>.+?)\/(?:\d+)(?:[\/])?';
        }

        if (!empty($cfg['alias'])) {
            return preg_quote($cfg['alias'], '/') . '\/(?<id>.+?)\/(?:\d+)(?:[\/])?';
        }

        return 'http[s]?:\/\/' . $cfg['bucket'] . '.s3.amazonaws.com\/(?<id>.+?)\/(?:\d+)(?:[\/](?:index\.html)?)?';
    }

    /**
     * Returns a fresh S3 instance
     */
    private function getS3($cfg) {
        if (isset($cfg['endpoint']) && $cfg['endpoint'] === 'mock') {
            $s3 = new MockS3($cfg['directory']);
        }
        else {
            $s3 = new S3($cfg['accesskey'], $cfg['secretkey']);
            $s3->setExceptions(true);

            if (!empty($cfg['endpoint'])) {
                $s3->setEndpoint($cfg['endpoint']);
            }
        }

        return $s3;
    }

    /**
     * Returns URL to the chart hosted on S3
     *
     * @param chart Chart class
     */
    public function getBucketName() {
        $cfg = $this->getConfig();
        return $cfg['bucket'];
    }

}
