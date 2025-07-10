(function($){
    $(function(){
        var frame;
        // Global and Meta selectors share class .rbseo-select-images
        $('.rbseo-select-images').on('click', function(e){
            e.preventDefault();
            var target = $(this).data('target');
            var list   = target === 'global' ? $('#rbseo-image-list-global') : $('#rbseo-image-list-meta');
            if ( frame ) { frame.open(); return; }
            frame = wp.media({ title:'Select images to preload', button:{ text:'Add to preload' }, multiple:true });
            frame.on('select', function(){
                var selection = frame.state().get('selection');
                selection.each(function(att){
                    var id  = att.id;
                    var url = att.attributes.sizes.thumbnail ? att.attributes.sizes.thumbnail.url : att.attributes.url;
                    if ( list.find('li[data-id="'+id+'"]' ).length === 0 ) {
                        var namePrefix = target === 'global' ? rbseo.optionKey+'[images][]' : 'rbseo_meta[images][]';
                        var li = $('<li data-id="'+id+'"><img src="'+url+'" /><span class="dashicons dashicons-no-alt rbseo-remove"></span><input type="hidden" name="'+namePrefix+'" value="'+id+'" /></li>');
                        list.append(li);
                    }
                });
            });
            frame.open();
        });
        // Remove image.
        $('body').on('click', '.rbseo-remove', function(){ $(this).closest('li').remove(); });
    });
})(jQuery);

