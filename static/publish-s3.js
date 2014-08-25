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
                modal = $('<div class="modal hide">' + data + '</div>').modal();
                chart = dw.backend.currentChart;
                
                // init copy to clipboard
                var copy = $('#copy-button', modal),
                    copySuccess = $('.copy-success', modal);

                copy.attr('data-clipboard-text', $('textarea', modal).val());
                
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
                }

                if (chart.get('publishedAt') && chart.get('publishedAt') < chart.get('lastModifiedAt')) {
                    // chart has been edited since last publication
                    var repubNote = $('.republish-note', modal).removeClass('hidden');
                    $('.btn-republish', modal).click(function() {
                        repubNote.addClass('hidden');
                        publishChart();
                    });
                }
            });
        }

        /*
         * publish chart
         */
        function publishChart() {
            var pending = true,
                progress = $('.publish-progress', modal).removeClass('hidden').show();
            
            $.ajax({
                url: '/api/charts/'+chart.get('id')+'/publish',
                type: 'post'
            }).done(function() {
                $('.progress .bar', progress).css('width', '100%'); 
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
        }

    });

});