<?php
/**
 * Datawrapper Publish S3
 */

use Aws\S3\S3Client;

class DatawrapperPlugin_PublishS3 extends DatawrapperPlugin {
    public function init() {
        $cfg = $this->getConfig();
        $plugin = $this;

        if (!isset($cfg['accesskey'])) return;

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
                        $chart = ChartQuery::create()->findPk($chartId);
                        if (!$chart) return;
                        $embedCodes = $chart->getMetadata('publish.embed-codes');
                        if (empty($embedCodes)) { $embedCodes = [];}
                        print (json_encode($embedCodes));
                    }
                );
            });

            DatawrapperHooks::register(DatawrapperHooks::PROVIDE_API, function ($app) use ($plugin) {
                return array(
                    'url' => 'publish-s3/download-zip/:chartId',
                    'method' => 'GET',
                    'action' => function ($chartId) use ($app, $plugin) {
                        disable_cache($app);
                        $chart = ChartQuery::create()->findPk($chartId);
                        $chartId = $chart->getId();
                        $chartUrl = $chart->getPublicUrl();

                        /* create temporary directory */
                        $tmp_folder = ROOT_PATH . "/tmp/" . $chartId . "-" . uniqid();
                        $filename = $chartId . '.zip';
                        $filepath = $tmp_folder . "/" . $filename;

                        mkdir($tmp_folder, 0777);

                        /* download with wget */
                        $mkdir_cmd = "mkdir -p " . $tmp_folder;
                        $wget_cmd = "wget -H -p -np -nd -nH -k http:" . $chartUrl;
                        $zip_command = 'zip -r -j ' . $filename . ' ./*';

                        $cmd = $mkdir_cmd . ' && cd ' . $tmp_folder . ' && ' . $wget_cmd;
                        exec($cmd);
                        exec('cd ' . $tmp_folder . ' && for file in ./*; do mv "$file" "${file%%\?*}"; done');

                        $index = file_get_contents($tmp_folder . '/index.html');
                        $index = str_replace(' src="http', ' src=\"http', $index);
                        $index = str_replace('\&quot;"', '\"', $index);
                        file_put_contents($tmp_folder . '/index.html', $index);

                        exec('cd ' . $tmp_folder . ' && ' . $zip_command);

                        $res = $app->response();

                        $res['Content-Description'] = 'File Transfer';
                        $res['Content-Type'] = 'application/octet-stream';
                        $res['Content-Disposition'] = 'attachment;filename="'.basename($filepath).'"';
                        $res['Expires'] = '0';
                        $res['Cache-Control'] = 'must-revalidate';
                        $res['Pragma'] = 'public';
                        $res['Content-Length'] = filesize($filepath);

                        readfile($filepath);

                        /* delete wget folder */
                        $remove_dir_command = "rm -rf " . $tmp_folder;
                        exec($remove_dir_command);
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

            DatawrapperHooks::register('publish_before_content', function() use ($cfg) {
                global $app;

                $user = DatawrapperSession::getUser();
                $org = $user->getCurrentOrganization();
                $preferredEmbed = "responsive";
                $orgEmbeds = null;

                if (isset($org) && ($org != null)) {
                    $embed = $org->getSettings('embed');

                    if (isset($embed["preferred_embed"])) {
                        $preferredEmbed = $embed['preferred_embed'];
                    }

                    if ($preferredEmbed == "custom") {
                        $customEmbeds = $embed['custom_embed'];
                        $orgEmbeds = $customEmbeds;
                        $orgEmbeds["selected"] = true;
                    }
                }

                $page = array(
                    "methods" => array(
                        array(
                            "id" => "responsive",
                            "title" => __("publish / embed / responsive"),
                            "text" => __("publish / embed / responsive / text"),
                            "template" => '<iframe id="datawrapper-chart-%chart_id%" src="%chart_url%" scrolling="no" frameborder="0" allowtransparency="true" allowfullscreen="allowfullscreen" webkitallowfullscreen="webkitallowfullscreen" mozallowfullscreen="mozallowfullscreen" oallowfullscreen="oallowfullscreen" msallowfullscreen="msallowfullscreen" style="width: 0; min-width: 100% !important;" height="%chart_height%"></iframe><script type="text/javascript">if("undefined"==typeof window.datawrapper)window.datawrapper={};window.datawrapper["%chart_id%"]={},window.datawrapper["%chart_id%"].embedDeltas=%embed_heights%,window.datawrapper["%chart_id%"].iframe=document.getElementById("datawrapper-chart-%chart_id%"),window.datawrapper["%chart_id%"].iframe.style.height=window.datawrapper["%chart_id%"].embedDeltas[Math.min(1e3,Math.max(100*Math.floor(window.datawrapper["%chart_id%"].iframe.offsetWidth/100),100))]+"px",window.addEventListener("message",function(a){if("undefined"!=typeof a.data["datawrapper-height"])for(var b in a.data["datawrapper-height"])if("%chart_id%"==b)window.datawrapper["%chart_id%"].iframe.style.height=a.data["datawrapper-height"][b]+"px"});</script>',
                            "selected" => ($preferredEmbed == "responsive" ? true : false)
                        ),
                        array(
                            "id" => "iframe",
                            "title" => __("publish / embed / iframe"),
                            "text" => __("publish / embed / iframe / text"),
                            "template" => '<iframe src="%chart_url%" scrolling="no" frameborder="0" allowtransparency="true" allowfullscreen="allowfullscreen" webkitallowfullscreen="webkitallowfullscreen" mozallowfullscreen="mozallowfullscreen" oallowfullscreen="oallowfullscreen" msallowfullscreen="msallowfullscreen" width="%chart_width%" height="%chart_height%"></iframe>',
                            "selected" => ($preferredEmbed == "iframe" ? true : false)
                        )
                    )
                );

                if ($orgEmbeds != null)
                    $page["methods"][] = $orgEmbeds;

                $app->render('plugins/publish-s3/publish-modal.twig', $page);
            });


            // provide static assets files
            $this->declareAssets(
                array('publish-s3.js'),
                "#/chart|map/[^/]+/publish#"
            );

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
