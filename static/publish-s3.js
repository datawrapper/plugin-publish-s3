require(['plugins/publish-s3/zeroclipboard'], function(ZeroClipboard) {
    ZeroClipboard.config({
        swfPath: '/static/plugins/publish-s3/ZeroClipboard.swf',
        forceHandCursor: true
    });

    function updateEmbedCode(chart, hasNewLink) {
        var embedCodes = $('.embed-holder > div'),
            codes = {};

        embedCodes.each(function(i, el) {
            var embedCodeTpl = $(el).data('tpl'),
              embedInput = $(el).find('input[type="text"]'),
              embedCopyBtn = $(el).find('.copy-button'),
              publish = chart.get('metadata.publish');

            $('#embed-width', modal).val(publish['embed-width']);
            $('#embed-height', modal).val(publish['embed-height']);

            var embedCode = embedCodeTpl
                .replace('%chart_url%', chart.get('publicUrl') || '')
                .replace('%chart_width%', publish['embed-width'])
                .replace('%chart_height%', publish['embed-height'])
                .replace(/%chart_id%/g, chart.get('id'));

            if (embedCodeTpl.indexOf("embed_heights") > -1) {
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
                embedCode = embedCode.replace('%embed_heights_escaped%', JSON.stringify(embedDeltas).replace(/"/g, "&quot;"))
                embedCode = embedCode.replace('%embed_heights%', JSON.stringify(embedDeltas));
            }

            embedInput.val(embedCode);
            codes[$(el).attr("id")] = embedCode;
            embedCopyBtn.attr('data-clipboard-text', embedCode);

            embedCopyBtn.click(function() {
                embedInput.select();
                try {
                    var successful = document.execCommand('copy');
                    var msg = successful ? 'successful' : 'unsuccessful';
                    console.log('Copying text command was ' + msg);
                } catch (err) {
                    // console.log('Oops, unable to copy');
                }
            });

        });

        if (hasNewLink) chart.set("metadata.publish.embed-codes", codes);
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

            // update view
            updateEmbedCode(chart, true);
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
        var pending = true,
            progress = $('.publish-progress', modal);

        function setProgress(percent) {
            $('.progress .bar', progress).css('width', percent + '%');
        }

        function publishFinished() {
            fetchEmbedCode();

            pending = false;

            setTimeout(function() {
                progress.fadeOut(200);

                setTimeout(function() {
                    progress.addClass('hidden');
                    $('.publish-success', modal).removeClass('hidden');
                    $('.embed-code').removeClass('hidden');
                    $('.hold', modal).show();

                    if (chart.get('publicVersion') > 1) {
                        $('#chart-url-change-warning').removeClass('hidden');
                    }
                }, 400);
            }, 1000);
        }

        function checkStatus() {
            $.ajax({
                dataType: 'json',
                url:      '/api/charts/'+chart.get('id')+'/publish/status',
                cache:    false
            }).success(function(res) {
                if (res != "") setProgress(res);

                if (pending) {
                    setTimeout(checkStatus, 300);
                }
            });
        }

        if (!chart.get('publishedAt')) {
            $('.hold', modal).hide();
        }

        progress.removeClass('hidden').show();
        $('.publish-success, .publish-error, .republish-note, #chart-url-change-warning', modal).addClass('hidden');

        $.ajax({
            url: '/api/charts/'+chart.get('id')+'/publish',
            type: 'post'
        })
        .success(function(res) {
            publishFinished();
        })
        .fail(function(res) {
            publishFinished();
        })

        checkStatus();
        setProgress(2);
    }

    function showModal() {
        modal = $('.publish-s3.modal').modal();
        chart = dw.backend.currentChart;

        updateEmbedCode(chart, false);
        updateChartLink(chart);

        $('#embed-width, #embed-height').change(function() {
            chart.set('metadata.publish.embed-width', $('#embed-width', modal).val());
            chart.set('metadata.publish.embed-height', $('#embed-height', modal).val());
            updateEmbedCode(chart, false);
        });

        // init copy to clipboard
        $('.copy-button', modal).each(function(i, el) {
            var
                copy        = $(el),
                copySuccess = $('.copy-success', modal),
                embedInput  = copy.parent().find('input [type="text"]');

            copy.attr('data-clipboard-text', embedInput.val());

            var client = new ZeroClipboard(copy);

            client.on('ready', function(readyEvent) {
                client.on('aftercopy', function(event) {
                    copySuccess.removeClass('hidden').show();
                    copySuccess.fadeOut(2000);
                });
            });

            copy.click(function() { return false; });
        });

        // kick off publishing or show success note

        if (!chart.get('publishedAt')
            || ((chart.get('publicUrl') != undefined) &&
                (chart.get('publicUrl').indexOf('charts.datawrapper.de') > -1) ||
                (chart.get('publicUrl').indexOf('s3.eu-central-1') > -1))) {
            // chart has never been published before, so let's publish it right now!
            publishChart();
        }
        else {
            // chart has been published before, show the link (but not the success message,
            // as it's confusing because you think you just published the chart again).
            $('.embed-code', modal).removeClass('hidden');
        }

        // show republish note if needed

        if (chart.get('publishedAt') && chart.get('publishedAt') < chart.get('lastModifiedAt')) {
            // chart has been edited since last publication
            $('.republish-note', modal).removeClass('hidden');
            $('.btn-republish', modal).click(publishChart);
        }

        $('.btn-publish', modal).click(publishChart);
    }

    $(function() {
        $('.chart-actions .action-publish-s3 a').click(function(e) {
            e.preventDefault();
            showModal();
        });
    });
});
