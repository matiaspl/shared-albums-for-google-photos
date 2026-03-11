/**
 * Classic editor: "Add Google Photos Album" button next to Add Media.
 * Triggers the TinyMCE dialog when in Visual mode, or inserts minimal shortcode in Text mode.
 */
(function() {
    'use strict';

    function handleClick(e) {
        var btn = e.target.closest('.jzsa-insert-album');
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();

        var editorId = btn.getAttribute('data-editor') || 'content';

        if (typeof tinymce !== 'undefined') {
            var ed = tinymce.get(editorId);
            if (ed && !ed.hidden) {
                ed.focus();
                ed.execCommand('jzsa_editor_button');
                return;
            }
        }

        var textarea = document.getElementById(editorId);
        if (textarea) {
            var link = window.prompt('Album share link (from Google Photos):');
            if (link) {
                link = link.trim();
                if (link) {
                    var shortcode = '[jzsa-album link="' + link.replace(/"/g, '&quot;') + '"]';
                    var start = textarea.selectionStart;
                    var end = textarea.selectionEnd;
                    var before = textarea.value.substring(0, start);
                    var after = textarea.value.substring(end);
                    textarea.value = before + shortcode + after;
                    textarea.selectionStart = textarea.selectionEnd = start + shortcode.length;
                    textarea.focus();
                }
            }
        }
    }

    function init() {
        document.body.addEventListener('click', handleClick, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
