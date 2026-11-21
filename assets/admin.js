(function ($) {
    'use strict';
    $(function () {
        var $taxonomy = $('#tlm-taxonomy');
        var $term = $('#tlm-term-search');
        var $termId = $('#tlm-term-id');
        var $page = $('#tlm-page-search');
        var $pageId = $('#tlm-page-id');

        function setupAutocomplete($input, $hidden, action, extraData) {
            if (!$input.length || $input.prop('readonly')) { return; }
            $input.autocomplete({
                minLength: 1,
                delay: 250,
                source: function (request, response) {
                    var data = {
                        action: action,
                        nonce: tlmAdmin.nonce,
                        term: request.term
                    };
                    if (extraData) { $.extend(data, extraData()); }
                    $.getJSON(tlmAdmin.ajaxUrl, data)
                        .done(function (result) { response(Array.isArray(result) ? result : []); })
                        .fail(function () { response([]); });
                },
                select: function (event, ui) {
                    $hidden.val(ui.item.id);
                    $input.val(ui.item.value);
                    return false;
                }
            });
            $input.on('input', function () { $hidden.val(''); });
        }

        setupAutocomplete($term, $termId, 'tlm_find_terms', function () {
            return {taxonomy: $taxonomy.val()};
        });
        setupAutocomplete($page, $pageId, 'tlm_find_pages');
        $taxonomy.on('change', function () {
            $term.val('');
            $termId.val('');
        });

        $('#tlm-mapping-form').on('submit', function (event) {
            if (!$termId.val() || !$pageId.val()) {
                event.preventDefault();
                window.alert(tlmAdmin.choose);
            }
        });
        $('.tlm-confirm').on('submit', function (event) {
            if (!window.confirm($(this).data('confirm') || tlmAdmin.confirm)) {
                event.preventDefault();
            }
        });
    });
})(jQuery);
