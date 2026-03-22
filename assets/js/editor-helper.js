(function($) {
    'use strict';

    // Register TinyMCE plugin
    tinymce.PluginManager.add('yaga_editor_button', function(editor, url) {

        // Handle placeholders
        editor.on('BeforeSetContent', function(e) {
            e.content = replaceShortcodesWithPlaceholders(e.content);
        });

        editor.on('GetContent', function(e) {
            e.content = restoreShortcodesFromPlaceholders(e.content);
        });

        /**
         * Escape text for use inside a double-quoted HTML attribute (TinyMCE-safe;
         * tinymce.utils.Entities is not available in all WP / TinyMCE builds).
         */
        function escapeAttrForData(atts) {
            if (atts == null) {
                return '';
            }
            return String(atts)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function replaceShortcodesWithPlaceholders(content) {
            return content.replace(/\[(?:jzsa-album|yaga-album)([^\]]*)\]/gi, function(match, atts) {
                return '<div class="yaga-editor-placeholder mceNonEditable" data-yaga-atts="' + escapeAttrForData(atts) + '">' +
                       '<div class="yaga-placeholder-content">' +
                       '<span class="dashicons dashicons-format-gallery"></span>' +
                       '<strong>Google Photos Album</strong>' +
                       '<code>[yaga-album' + atts + ']</code>' +
                       '</div>' +
                       '</div>';
            });
        }

        function restoreShortcodesFromPlaceholders(content) {
            var $html = $('<div>' + content + '</div>');
            $html.find('.yaga-editor-placeholder').each(function() {
                var atts = $(this).attr('data-yaga-atts');
                $(this).replaceWith('[yaga-album' + atts + ']');
            });
            return $html.html();
        }

        function openYagaAlbumDialog() {
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
                    {type: 'checkbox', name: 'filename_display_photographer', label: 'Filename: photographer name only (CamelCase / YAPA-style)'},
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
                    if (e.data.show_filename) {
                        atts += ' show-filename="true"';
                        if (e.data.filename_display_photographer) {
                            atts += ' filename-display="photographer"';
                        }
                    }
                    if (e.data.show_info) atts += ' show-info="true"';
                    if (!e.data.autoplay) atts += ' autoplay="false"';
                    if (e.data.image_width !== '1920') atts += ' image-width="' + e.data.image_width + '"';
                    if (e.data.image_height !== '1440') atts += ' image-height="' + e.data.image_height + '"';
                    editor.insertContent('[yaga-album ' + atts + ']');
                }
            });
        }

        editor.addCommand('yaga_editor_button', openYagaAlbumDialog);
        editor.addButton('yaga_editor_button', {
            title: 'Add YAPA Google Photos album',
            icon: 'image',
            cmd: 'yaga_editor_button'
        });
    });

})(jQuery);
