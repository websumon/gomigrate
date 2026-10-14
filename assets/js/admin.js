(function ($, window) {
    'use strict';

    var config = window.GoMigrateAdmin || {};
    var messages = config.messages || {};

    function showMessage(message, error) {
        var box = $('#gomigrate-msg');
        box.removeAttr('hidden')
            .removeClass('gomigrate-message--error gomigrate-message--success')
            .addClass(error ? 'gomigrate-message--error' : 'gomigrate-message--success')
            .text(message || messages.operationFailed || 'Operation failed.');
    }

    function updateProgress(selector, percent, status, current, logs) {
        var box = $(selector);
        var list = box.find('.gomigrate-live-preview__list');

        box.show();
        box.find('.gomigrate-progress__fill').css('width', percent + '%');
        box.find('.gomigrate-progress__percent').text(percent + '%');
        box.find('.gomigrate-progress__status').text(status || '');
        box.find('.gomigrate-progress__current').text(current ? ' • ' + current : '');

        if (Array.isArray(logs)) {
            list.empty();
            logs.slice().reverse().forEach(function (entry) {
                var item = $('<div>', { 'class': 'gomigrate-log-item' });
                $('<span>', { 'class': 'gomigrate-log-time', 'text': entry.time || '' }).appendTo(item);
                $('<span>', { 'class': 'gomigrate-log-message', 'text': entry.message || '' }).appendTo(item);
                item.appendTo(list);
            });
        }
    }

    function run(type, job, formData) {
        formData.append('action', 'gomigrate_' + type);
        formData.append('nonce', config.nonce || '');

        if (job) {
            formData.append('job', job);
        }

        $.ajax({
            url: config.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            timeout: 0
        }).done(function (response) {
            if (!response.success) {
                showMessage(response.data && response.data.message ? response.data.message : messages.operationFailed, true);
                return;
            }

            var box = type === 'export' ? '#exp' : '#imp';
            var percent = parseInt(response.data.percent || 0, 10);
            var status = response.data.complete ? messages.completed : (type === 'export' ? messages.exporting : messages.importing);

            updateProgress(box, percent, status, response.data.current || '', response.data.logs || []);

            if (response.data.complete) {
                showMessage(type === 'export' ? messages.exportCompleted : messages.importCompleted, false);

                if (response.data.download) {
                    $(box).find('.download').html(
                        $('<a>', {
                            'class': 'button button-primary',
                            'href': response.data.download,
                            'text': messages.download
                        })
                    );
                }
                return;
            }

            window.setTimeout(function () {
                run(type, response.data.job, new FormData());
            }, 120);
        }).fail(function (xhr, textStatus) {
            var message = '';
            if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            } else if (xhr && xhr.responseText) {
                try {
                    var parsed = JSON.parse(xhr.responseText);
                    if (parsed && parsed.data && parsed.data.message) {
                        message = parsed.data.message;
                    }
                } catch (ignore) {}
            }
            if (!message && textStatus === 'timeout') {
                message = 'The server took too long to respond. Check the PHP/server error log and server timeout settings.';
            }
            showMessage(message || messages.requestFailed, true);
        });
    }

    $('.gomigrate-tab').on('click', function () {
        var tab = $(this).data('tab');
        $('.gomigrate-tab').removeClass('button-primary');
        $(this).addClass('button-primary');
        $('#gomigrate-export, #gomigrate-import').hide();
        $('#gomigrate-' + tab).show();
    });

    $('#gomigrate-export-form').on('submit', function (event) {
        event.preventDefault();
        $('#exp .gomigrate-live-preview__list').empty();
        $('#exp .download').empty();
        run('export', null, new FormData(this));
    });

    $('#gomigrate-import-form').on('submit', function (event) {
        event.preventDefault();
        $('#imp .gomigrate-live-preview__list').empty();
        run('import', null, new FormData(this));
    });
}(jQuery, window));
