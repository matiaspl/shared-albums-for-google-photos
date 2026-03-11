(function($) {
    'use strict';

    // Register TinyMCE plugin
    tinymce.PluginManager.add('jzsa_editor_button', function(editor, url) {
        
        // Handle placeholders
        editor.on('BeforeSetContent', function(e) {
            e.content = replaceShortcodesWithPlaceholders(e.content);
        });

        editor.on('GetContent', function(e) {
            e.content = restoreShortcodesFromPlaceholders(e.content);
        });

        function replaceShortcodesWithPlaceholders(content) {
            return content.replace(/\[jzsa-album([^\]]*)\]/g, function(match, atts) {
                return '<div class="jzsa-editor-placeholder mceNonEditable" data-jzsa-atts="' + tinymce.utils.Entities.encodeAllRaw(atts) + '">' +
                       '<div class="jzsa-placeholder-content">' +
                       '<span class="dashicons dashicons-format-gallery"></span>' +
                       '<strong>Google Photos Album</strong>' +
                       '<code>[jzsa-album' + atts + ']</code>' +
                       '</div>' +
                       '</div>';
            });
        }

        function restoreShortcodesFromPlaceholders(content) {
            var $html = $('<div>' + content + '</div>');
            $html.find('.jzsa-editor-placeholder').each(function() {
                var atts = $(this).attr('data-jzsa-atts');
                $(this).replaceWith('[jzsa-album' + atts + ']');
            });
            return $html.html();
        }

        editor.addButton('jzsa_editor_button', {
            title: 'Add Google Photos Album',
            icon: 'image',
            onclick: function() {
                editor.windowManager.open({
                    title: 'Insert Google Photos Album',
                    body: [
                        {type: 'textbox', name: 'link', label: 'Album Share Link (Required)', minWidth: 400},
                        {type: 'listbox', name: 'mode', label: 'Gallery Mode', 
                            values: [
                                {text: 'Single Photo', value: 'single'},
                                {text: 'Carousel', value: 'carousel'},
                                {text: 'Carousel to Single', value: 'carousel-to-single'}
                            ]
                        },
                        {type: 'checkbox', name: 'mosaic', label: 'Enable Mosaic Preview'},
                        {type: 'listbox', name: 'mosaic_position', label: 'Mosaic Position',
                            values: [
                                {text: 'Right', value: 'right'},
                                {text: 'Left', value: 'left'},
                                {text: 'Top', value: 'top'},
                                {text: 'Bottom', value: 'bottom'}
                            ]
                        },
                        {type: 'textbox', name: 'mosaic_count', label: 'Mosaic Count', value: '4'},
                        {type: 'checkbox', name: 'show_filename', label: 'Show Filename Label'},
                        {type: 'checkbox', name: 'show_info', label: 'Show Info Panel (Date/Camera)'},
                        {type: 'checkbox', name: 'autoplay', label: 'Enable Autoplay', checked: true},
                        {type: 'textbox', name: 'image_width', label: 'Image Width', value: '1920'},
                        {type: 'textbox', name: 'image_height', label: 'Image Height', value: '1440'}
                    ],
                    onsubmit: function(e) {
                        var atts = 'link="' + e.data.link + '"';
                        
                        if (e.data.mode !== 'single') atts += ' mode="' + e.data.mode + '"';
                        if (e.data.mosaic) {
                            atts += ' mosaic="true"';
                            if (e.data.mosaic_position !== 'right') atts += ' mosaic-position="' + e.data.mosaic_position + '"';
                            if (e.data.mosaic_count && e.data.mosaic_count !== '4') atts += ' mosaic-count="' + e.data.mosaic_count + '"';
                        }
                        if (e.data.show_filename) atts += ' show-filename="true"';
                        if (e.data.show_info) atts += ' show-info="true"';
                        if (!e.data.autoplay) atts += ' autoplay="false"';
                        if (e.data.image_width !== '1920') atts += ' image-width="' + e.data.image_width + '"';
                        if (e.data.image_height !== '1440') atts += ' image-height="' + e.data.image_height + '"';

                        editor.insertContent('[jzsa-album ' + atts + ']');
                    }
                });
            }
        });
    });

})(jQuery);
