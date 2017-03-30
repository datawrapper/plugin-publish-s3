<?php
/**
 * Datawrapper Publish S3
 */

class DatawrapperPlugin_PublishS3 extends DatawrapperPlugin {
    public function init() {
        $cfg = $this->getConfig();
        $plugin = $this;

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
                        $chart = ChartQuery::create()->findPk($chartId);
                        $chartId = $chart->getId();
                        $chartUrl = $chart->getPublicUrl();

                        /* create temporary directory */
                        $tmp_folder = dirname(__FILE__) . "/tmp/" . $chartId;
                        mkdir($tmp_folder, 0777);

                        /* download with wget */ 
                        $wget_cmd = "wget -nd -nH -p -np -k -H http:" . $chartUrl . " -P " . $tmp_folder;
                        exec($wget_cmd);

                        $filename = $chartId . '.zip';
                        $zip_command = 'zip -j -r9 ' . $tmp_folder . '/' . $filename . ' ' . $tmp_folder;
                        $zip_file = exec($zip_command);

                        $filepath = $tmp_folder . "/" . $filename;

                        $res = $app->response();

                        $res['Content-Description'] = 'File Transfer';
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
                            "template" => '<iframe id="datawrapper-chart-%chart_id%" src="%chart_url%" frameborder="0" allowtransparency="true" allowfullscreen="allowfullscreen" webkitallowfullscreen="webkitallowfullscreen" mozallowfullscreen="mozallowfullscreen" oallowfullscreen="oallowfullscreen" msallowfullscreen="msallowfullscreen" width="100%" height="%chart_height%"></iframe><script type="text/javascript">if("undefined"==typeof window.datawrapper)window.datawrapper={};window.datawrapper["%chart_id%"]={},window.datawrapper["%chart_id%"].embedDeltas=%embed_heights%,window.datawrapper["%chart_id%"].iframe=document.getElementById("datawrapper-chart-%chart_id%"),window.datawrapper["%chart_id%"].iframe.style.height=window.datawrapper["%chart_id%"].embedDeltas[Math.min(1e3,Math.max(100*Math.floor(window.datawrapper["%chart_id%"].iframe.offsetWidth/100),100))]+"px",window.addEventListener("message",function(a){if("undefined"!=typeof a.data["datawrapper-height"])for(var b in a.data["datawrapper-height"])if("%chart_id%"==b)window.datawrapper["%chart_id%"].iframe.style.height=a.data["datawrapper-height"][b]+"px"});</script>',
                            "selected" => ($preferredEmbed == "responsive" ? true : false)
                        ),
                        array(
                            "id" => "iframe",
                            "title" => __("publish / embed / iframe"),
                            "text" => __("publish / embed / iframe / text"),
                            "template" => '<iframe src="%chart_url%" frameborder="0" allowtransparency="true" allowfullscreen="allowfullscreen" webkitallowfullscreen="webkitallowfullscreen" mozallowfullscreen="mozallowfullscreen" oallowfullscreen="oallowfullscreen" msallowfullscreen="msallowfullscreen" width="%chart_width%" height="%chart_height%"></iframe>',
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

    public function getRequiredLibraries() {
        return array('vendor/S3.php', 'MockS3.php');
    }

    /**
     * pushs a list of files to S3
     *
     * @param files list of file descriptions in the format [localFile, remoteFile, contentType]
     * e.g.
     *
     * array(
     *     array('path/to/local/file', 'remote/file', 'text/plain')
     * )
     */
    public function publish($files) {
        $cfg = $this->getConfig();
        $s3  = $this->getS3($cfg);

        foreach ($files as $info) {
            $header = array();

            if (count($info) > 2) {
                $header['Content-Type'] = $info[2];
            }

            if (isset($cfg['cache-control'])) {
                $header['cache-control'] = $cfg['cache-control'];
            }

            try {
                $result = $s3->putObjectFile($info[0], $cfg['bucket'], $info[1], S3::ACL_PUBLIC_READ, array(), $header);
            }
            catch (Exception $e) {
                // sometimes, S3 has hickups. It can scramble the MD5 Digest, kill connections or do other random
                // stuff -- let's try the operation again if it failed.
                try {
                    $s3->putObjectFile($info[0], $cfg['bucket'], $info[1], S3::ACL_PUBLIC_READ, array(), $header);
                }
                catch (Exception $e) {
                    // well, time to scream for someone to do something
                    trigger_error($e->getMessage(), E_USER_WARNING);
                }
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
        $s3  = $this->getS3($cfg);

        foreach ($files as $file) {
            $s3->deleteObject($cfg['bucket'], $file);
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
