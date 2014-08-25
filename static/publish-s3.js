require([], function() {
	
    $(function() {

        var action = $('.chart-actions .action-publish-s3'),
            modal;

        $('a', action).click(function(e) {
            e.preventDefault();
            showModal();
        });

        function showModal() {
        	console.log('foo');
            $.get('/plugins/publish-s3/publish-modal.twig', function(data) {
                modal = $('<div class="modal hide">' + data + '</div>').modal();
                
            });
        }

    });

});