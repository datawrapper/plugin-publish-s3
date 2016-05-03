require(['plugins/publish-s3/zeroclipboard'], function(ZeroClipboard) {
    ZeroClipboard.config({
        swfPath: '/static/plugins/publish-s3/ZeroClipboard.swf',
        forceHandCursor: true
    });

    var isSSL = null, alias, aliasSSL, modal, chart;

    function applyChosenAlias(chart) {
        var chartUrl = chart.get('publicUrl') || '';

        // only do something if there is SSL support present
        if (isSSL !== null) {
            var hasSSL = chartUrl.indexOf(aliasSSL) === 0;

            // switch alias if needed
            if (hasSSL !== isSSL) {
                chartUrl = chartUrl.replace(hasSSL ? aliasSSL : alias, hasSSL ? alias : aliasSSL);
                chart.set('publicUrl', chartUrl);
            }
        }

        return chartUrl;
    }

    function updateEmbedCode(chart) {
        var embedInput   = $('input.embed-code', modal);
        var embedCodeTpl = embedInput.data('embed-template');
        var publish      = chart.get('metadata.publish');

        $('#embed-width', modal).val(publish['embed-width']);
        $('#embed-height', modal).val(publish['embed-height']);

        var embedCode = embedCodeTpl
            .replace('%chart_url%', chart.get('publicUrl') || '')
            .replace('%chart_width%', publish['embed-width'])
            .replace('%chart_height%', publish['embed-height'])
            .replace(/%chart_id%/g, chart.get('id'));

        if (embedCodeTpl.indexOf("%embed_heights%") > -1) {
            var embedDeltas = {
                100: 0,
                200: 0, 
                300: 0,
                400: 0,
                500: 0, 
                600: 0,
                700: 0,
                800: 0, 
                900: 0,
                1000: 0,
            };

            var previewChart = $($('#iframe-vis')[0].contentDocument);

            var defaultHeight = $('h1', previewChart).height() 
                         + $('.chart-intro', previewChart).height() 
                         + $('.dw-chart-notes', previewChart).height();

            var totalHeight = $('#iframe-vis').height();

            for (var width in embedDeltas) {
                previewChart.find('h1, .chart-intro, .dw-chart-notes').css('width', width + "px");

                var height = $('h1', previewChart).height() 
                             + $('.chart-intro', previewChart).height() 
                             + $('.dw-chart-notes', previewChart).height();

                embedDeltas[width] = totalHeight + (height - defaultHeight);
            }

            previewChart.find('h1, .chart-intro, .dw-chart-notes').css('width', "");
            embedCode = embedCode.replace('%embed_heights%', JSON.stringify(embedDeltas).replace(/"/g, "&quot;"))
        } 

        embedInput.val(embedCode);
    }

    function updateChartLink(chart) {
        var url = chart.get('publicUrl');

        $('.chart-embed-url', modal).attr('href', url || '#').text(url || 'N/A');
    }

    function fetchEmbedCode() {
        $.ajax({
            dataType: 'json',
            url:      '/api/charts/' + chart.get('id'),
            cache:    false
        }).success(function(d) {
            // update current chart object
            chart.attributes(d.data);
            applyChosenAlias(chart);

            // update view
            updateEmbedCode(chart);
            updateChartLink(chart);
        });
    }

    function modalExitStopper(stop) {
        $('.pbs3').remove();

        if (stop) {
            // put another transparent layer in front of the BS backdrop
            var myBackdrop = $('<div class="modal-backdrop pbs3" style="background:transparent"></div>');
            $('.modal-backdrop').after(myBackdrop);
        }

        $('button, .btn', modal).toggleClass('disabled', stop).prop('disabled', stop);
    }

    function publishChart() {
        var
            pending  = true,
            progress = $('.publish-progress', modal);

        function setProgress(percent) {
            $('.progress .bar', progress).css('width', percent + '%');
        }

        function checkStatus() {
            $.ajax({
                dataType: 'json',
                url:      '/api/charts/'+chart.get('id')+'/publish/status',
                cache:    false
            }).success(function(res) {
                setProgress(res);
                if (pending) setTimeout(checkStatus, 300);

                if (res == 100) {
                    fetchEmbedCode();

                    setTimeout(function() {
                        progress.fadeOut(200);

                        setTimeout(function() {
                            progress.addClass('hidden');
                            $('.publish-success', modal).removeClass('hidden');
                            $('#chart-publish-url-link').removeClass('hidden');
                            $('.hold', modal).show();

                            if (chart.get('publicVersion') > 1) {
                                $('#chart-url-change-warning').removeClass('hidden');
                            }
                        }, 400);
                    }, 1000);
                }
            });
        }

        if (!chart.get('publishedAt')) {
            $('.hold', modal).hide();
        }

        progress.removeClass('hidden').show();
        $('.publish-success, .republish-note, #chart-url-change-warning', modal).addClass('hidden');

        // prevent people from leaving the popup
        modalExitStopper(true);

        $.ajax({
            url: '/api/charts/'+chart.get('id')+'/publish',
            type: 'post'
        }).done(function() {
            setProgress(100);
            modalExitStopper(false);
            pending = false;
        }).fail(function() {
            // this typically happens when the request times out,
            // which doesn't actually mean it has *failed*. 
            // so we continue checking
            modalExitStopper(false);
        });

        // in the meantime, check status periodically
        checkStatus();
        setProgress(2);

        setTimeout(fetchEmbedCode, 1000);
    }

    function showModal() {
        $.get('/plugins/publish-s3/publish-modal.twig', function(data) {
            modal = $('<div class="publish-chart-action-modal modal hide">' + data + '</div>').modal();
            chart = dw.backend.currentChart;

            // check for SSL support and read the configured aliases

            var $ssl = $('.publish-ssl');

            if ($ssl.length) {
                alias    = $ssl.data('alias');
                aliasSSL = $ssl.data('alias-ssl');
                isSSL    = (chart.get('publicUrl') || '').indexOf(aliasSSL) === 0;

                $ssl.find('input').prop('checked', isSSL).on('change', function() {
                    isSSL = $(this).prop('checked');

                    // update view
                    applyChosenAlias(chart);
                    updateEmbedCode(chart);
                    updateChartLink(chart);
                });
            }

            // init view

            applyChosenAlias(chart);
            updateEmbedCode(chart);
            updateChartLink(chart);

            $('#embed-width, #embed-height').change(function() {
                chart.set('metadata.publish.embed-width', $('#embed-width', modal).val());
                chart.set('metadata.publish.embed-height', $('#embed-height', modal).val());
                updateEmbedCode(chart);
            });

            // init copy to clipboard

            var
                copy        = $('#copy-button', modal),
                copySuccess = $('.copy-success', modal),
                embedInput  = $('input.embed-code', modal);

            copy.attr('data-clipboard-text', embedInput.val());

            var client = new ZeroClipboard(copy);

            client.on('ready', function(readyEvent) {
                client.on('aftercopy', function(event) {
                    copySuccess.removeClass('hidden').show();
                    copySuccess.fadeOut(2000);
                });
            });

            // kick off publishing or show success note

            if (!chart.get('publishedAt')) {
                // chart has never been published before, so let's publish it right now!
                publishChart();
            }
            else {
                // chart has been published before, show the link (but not the success message,
                // as it's confusing because you think you just published the chart again).
                $('#chart-publish-url-link', modal).removeClass('hidden');
            }

            // show republish note if needed

            if (chart.get('publishedAt') && chart.get('publishedAt') < chart.get('lastModifiedAt')) {
                // chart has been edited since last publication
                $('.republish-note', modal).removeClass('hidden');
                $('.btn-republish', modal).click(publishChart);
            }

            $('.btn-publish', modal).click(publishChart);
        });
    }

    $(function() {
        $('.chart-actions .action-publish-s3 a').click(function(e) {
            e.preventDefault();
            showModal();
        });
    });
});
