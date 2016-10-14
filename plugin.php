<?php
/**
 * Datawrapper Publish S3
 */

class DatawrapperPlugin_PublishS3 extends DatawrapperPlugin {
    public function init() {
        $cfg = $this->getConfig();

        if ($cfg) {

            $can_publish = true;
            
            if (isset($cfg['limit-views']) && $cfg['limit-views'] > 0) {
                $user = DatawrapperSession::getUser();
                if (count($user->getProducts()) == 0) {
                    // user has no products => free account
                    $analytics = DatawrapperPluginManager::getInstance('analytics-pixeltracker');
                    if (!empty($analytics)) {
                        $viewsThisMonth = $analytics->getUserChartViewsCurrentMonth($user->getID());
                        if ($viewsThisMonth > $cfg['limit-views']) {
                            $can_publish = false;
                        }
                    }
                }
            }

            DatawrapperHooks::register(DatawrapperHooks::PUBLISH_FILES, array($this, 'publish'));    
            DatawrapperHooks::register(DatawrapperHooks::UNPUBLISH_FILES, array($this, 'unpublish'));
            DatawrapperHooks::register(DatawrapperHooks::GET_PUBLISHED_URL, array($this, 'getUrl'));
            DatawrapperHooks::register(DatawrapperHooks::GET_PUBLISH_STORAGE_KEY, array($this, 'getBucketName'));
            
            if ($can_publish) {    
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
                                "template" => '<iframe id="datawrapper-chart-%chart_id%" src="%chart_url%" frameborder="0" allowtransparency="true" allowfullscreen="allowfullscreen" webkitallowfullscreen="webkitallowfullscreen" mozallowfullscreen="mozallowfullscreen" oallowfullscreen="oallowfullscreen" msallowfullscreen="msallowfullscreen" width="100%" height="%chart_height%"></iframe><script type="text/javascript">"undefined"==typeof window.datawrapper&&(window.datawrapper={}),window.datawrapper["%chart_id%"].embedDeltas=%embed_heights%,window.datawrapper["%chart_id%"].iframe=document.getElementById("datawrapper-chart-%chart_id%"),window.datawrapper["%chart_id%"].iframe.style.height=window.datawrapper["%chart_id%"].embedDeltas[Math.min(1e3,Math.max(100*Math.floor(window.datawrapper["%chart_id%"].iframe.offsetWidth/100),100))]+"px",window.addEventListener("message",function(a){if("undefined"!=typeof a.data["datawrapper-height"])for(var b in a.data["datawrapper-height"])"axHs4"==a.data["datawrapper-height"][b]&&(window.datawrapper.axHs4.iframe.style.height=a.data["datawrapper-height"][b]+"px")});</script>',
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
            } else {
                DatawrapperHooks::register(DatawrapperHooks::GET_CHART_ACTIONS, function($chart) {
                    return array(
                        'id' => 'publish-s3',
                        'icon' => 'rocket',
                        'title' => __('btn / upgrade to publish'),
                        'order' => 100,
                        'url' => '/plans/single',
                        'class' => 'promo',
                        'banner' => array(
                            'text' => 'SINGLE',
                            'style' => 'background: rgba(128, 0, 128,0.5)'
                        )
                    );
                });


                DatawrapperHooks::register('publish_before_content', function() use ($cfg) {
                    echo '<div class="alert alert-warning" style="text-align:center;margin-top:20px; margin-bottom:-10px">';
                    echo str_replace(['[[', ']]', '%d'],
                        ['<a href="/plans/single">', '</a>', number_format2($cfg['limit-views'])],
                        __('reached limit - please upgrade'));
                    echo '</div>';
                });
            }
            
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
