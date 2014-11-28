require(['plugins/publish-s3/zeroclipboard'], function(ZeroClipboard) {

    ZeroClipboard.config({
        swfPath: '/static/plugins/publish-s3/ZeroClipboard.swf',
        forceHandCursor: true
    });

    $(function() {

        var action = $('.chart-actions .action-publish-s3'),
            modal, chart;

        $('a', action).click(function(e) {
            e.preventDefault();
            showModal();
        });

        function showModal() {
            $.get('/plugins/publish-s3/publish-modal.twig', function(data) {
                modal = $('<div class="publish-chart-action-modal modal hide">' + data + '</div>').modal();
                chart = dw.backend.currentChart;

                // init copy to clipboard
                var copy = $('#copy-button', modal),
                    copySuccess = $('.copy-success', modal),
                    embedInput = $('input.embed-code', modal),
                    embedCodeTpl = embedInput.data('embed-template');

                embedInput.val(embedCodeTpl
                    .replace('%chart_url%', chart.get('publicUrl'))
                    .replace('%chart_width%', chart.get('metadata.publish.embed-width'))
                    .replace('%chart_height%', chart.get('metadata.publish.embed-height'))
                );

                copy.attr('data-clipboard-text', embedInput.val());

                var client = new ZeroClipboard(copy);

                client.on('ready', function(readyEvent) {
                    client.on('aftercopy', function(event) {
                        copySuccess.removeClass('hidden').show();
                        copySuccess.fadeOut(2000);
                    });
                });

                if (!chart.get('publishedAt')) {
                    // chart has never been published before, so let's publish it right now!
                    publishChart();
                } else {
                    // chart has been published before, show success
                    $('.publish-success', modal).removeClass('hidden');
                }

                if (chart.get('publishedAt') && chart.get('publishedAt') < chart.get('lastModifiedAt')) {
                    // chart has been edited since last publication
                    var repubNote = $('.republish-note', modal).removeClass('hidden');
                    $('.btn-republish', modal).click(function() {
                        repubNote.addClass('hidden');
                        publishChart();
                    });
                }

                $('.btn-publish', modal).click(function() {
                    publishChart();
                });

                var $ssl = $('.publish-ssl');

                if ($ssl.length) {
                    var alias = $ssl.data('alias'),
                        aliasSSL = $ssl.data('alias-ssl'),
                        isSSL = chart.get('publicUrl') && chart.get('publicUrl').substr(0, aliasSSL.length) == aliasSSL;

                    $ssl.find('input')
                        // initial check
                        .prop('checked', isSSL)
                        // update embed code
                        .on('change', function() {
                            isSSL = $(this).prop('checked');
                            if (isSSL) {
                                chart.set('publicUrl', chart.get('publicUrl').replace(alias, aliasSSL));
                                embedInput.val(embedInput.val().replace(alias, aliasSSL));
                            } else {
                                chart.set('publicUrl', chart.get('publicUrl').replace(aliasSSL, alias));
                                embedInput.val(embedInput.val().replace(aliasSSL, alias));
                            }
                        });
                }

                /*
                 * publish chart
                 */
                function publishChart() {
                    var pending = true,
                        progress = $('.publish-progress', modal).removeClass('hidden').show();
                    $('.publish-success', modal).addClass('hidden');

                    $.ajax({
                        url: '/api/charts/'+chart.get('id')+'/publish',
                        type: 'post'
                    }).done(function() {
                        $('.progress .bar', progress).css('width', '100%');
                        updateEmbedCode();
                        setTimeout(function() {
                            progress.fadeOut(200);
                            setTimeout(function() {
                                progress.addClass('hidden');
                                $('.publish-success', modal).removeClass('hidden');

                            }, 400);
                        }, 1000);
                        pending = false;
                    }).fail(function() {
                        console.log('failed');
                        pending = false;
                    });
                    // in the meantime, check status periodically
                    checkStatus();
                    $('.progress .bar', progress).css('width', '2%');
                    function checkStatus() {
                        $.getJSON('/api/charts/'+chart.get('id')+'/publish/status', function(res) {
                            $('.progress .bar', progress).css('width', res+'%');
                            if (pending) setTimeout(checkStatus, 300);
                        });
                    }
                    setTimeout(updateEmbedCode, 1000);
                }

                function updateEmbedCode() {
                    $.getJSON('/api/charts/'+chart.get('id'), function(d) {
                        // update public url, if SSL is set
                        chart.attributes(d.data);
                        if (isSSL) {
                            chart.set('publicUrl', d.data.publicUrl.replace(alias, aliasSSL));
                        }
                        embedInput.val(embedCodeTpl
                            .replace('%chart_url%', chart.get('publicUrl'))
                            .replace('%chart_width%', d.data.metadata.publish['embed-width'])
                            .replace('%chart_height%', d.data.metadata.publish['embed-height'])
                        );
                        $('.chart-embed-url', modal).attr('href', chart.get('publicUrl'))
                            .html(chart.get('publicUrl'));
                    });
                }
            });
        }

    });

});