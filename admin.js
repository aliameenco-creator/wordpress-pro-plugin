(function ($) {
    'use strict';

    // --- Single image: Generate button in media edit screen ---
    $(document).on('click', '.ai-generate-alt-btn', function (e) {
        e.preventDefault();
        var btn = $(this);
        var spinner = btn.siblings('.ai-alt-spinner');
        var attachmentId = btn.data('attachment-id');

        btn.prop('disabled', true);
        spinner.addClass('is-active');

        $.post(aiAltText.ajaxUrl, {
            action: 'ai_generate_alt_text',
            nonce: aiAltText.nonce,
            attachment_id: attachmentId
        }, function (response) {
            spinner.removeClass('is-active');
            btn.prop('disabled', false);

            if (response.success) {
                // Update the alt text field in the media modal or edit page
                var altField = btn.closest('.compat-attachment-fields, .attachment-details')
                    .find('input[name*="[_wp_attachment_image_alt]"], input[data-setting="alt"], [data-setting="alt"] input');

                if (altField.length) {
                    altField.val(response.data.alt_text).trigger('change');
                }

                // Also try the media modal's alt field
                var modalAlt = btn.closest('.attachment-details').find('input[data-setting="alt"]');
                if (modalAlt.length) {
                    modalAlt.val(response.data.alt_text).trigger('change');
                }

                btn.text('✅ Done!');
                setTimeout(function () {
                    btn.text('🤖 Generate Alt Text with AI');
                }, 2000);
            } else {
                alert('Error: ' + response.data);
            }
        }).fail(function () {
            spinner.removeClass('is-active');
            btn.prop('disabled', false);
            alert('Request failed. Please try again.');
        });
    });

    // --- Bulk page: Select all ---
    $('#ai-select-all, #ai-header-check').on('change', function () {
        $('.ai-image-check').prop('checked', this.checked);
    });

    // --- Bulk page: Select only missing alt text ---
    $('#ai-select-missing').on('change', function () {
        if (this.checked) {
            $('#ai-select-all, #ai-header-check').prop('checked', false);
            $('.ai-image-check').prop('checked', false);
            $('tr[data-has-alt="0"] .ai-image-check').prop('checked', true);
        } else {
            $('tr[data-has-alt="0"] .ai-image-check').prop('checked', false);
        }
    });

    // --- Bulk page: Single row generate ---
    $(document).on('click', '.ai-single-generate-btn', function () {
        var btn = $(this);
        var id = btn.data('id');
        var row = btn.closest('tr');

        btn.prop('disabled', true).text('...');
        row.find('.ai-status').text('Generating...');

        $.post(aiAltText.ajaxUrl, {
            action: 'ai_generate_alt_text',
            nonce: aiAltText.nonce,
            attachment_id: id
        }, function (response) {
            if (response.success) {
                row.find('.ai-current-alt').text(response.data.alt_text);
                row.find('.ai-status').html('<span style="color:green;">✅ Done</span>');
                row.attr('data-has-alt', '1');
            } else {
                row.find('.ai-status').html('<span style="color:red;">❌ ' + response.data + '</span>');
            }
            btn.prop('disabled', false).text('Generate');
        }).fail(function () {
            row.find('.ai-status').html('<span style="color:red;">❌ Failed</span>');
            btn.prop('disabled', false).text('Generate');
        });
    });

    // --- Bulk page: Bulk generate ---
    $('#ai-bulk-generate-btn').on('click', function () {
        var ids = [];
        $('.ai-image-check:checked').each(function () {
            ids.push($(this).val());
        });

        if (ids.length === 0) {
            alert('Please select at least one image.');
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true);
        $('#ai-bulk-spinner').addClass('is-active');
        $('#ai-bulk-progress').show();

        var total = ids.length;
        var completed = 0;
        var succeeded = 0;
        var failed = 0;

        // Process one at a time to avoid API rate limits
        function processNext() {
            if (ids.length === 0) {
                $('#ai-bulk-spinner').removeClass('is-active');
                btn.prop('disabled', false);
                $('#ai-bulk-status').text('Complete! ' + succeeded + ' succeeded, ' + failed + ' failed out of ' + total + '.');
                return;
            }

            var id = ids.shift();
            var row = $('tr[data-id="' + id + '"]');
            row.find('.ai-status').text('Generating...');

            $.post(aiAltText.ajaxUrl, {
                action: 'ai_generate_alt_text',
                nonce: aiAltText.nonce,
                attachment_id: id
            }, function (response) {
                completed++;
                if (response.success) {
                    succeeded++;
                    row.find('.ai-current-alt').text(response.data.alt_text);
                    row.find('.ai-status').html('<span style="color:green;">✅ Done</span>');
                    row.attr('data-has-alt', '1');
                } else {
                    failed++;
                    row.find('.ai-status').html('<span style="color:red;">❌ ' + response.data + '</span>');
                }
                updateProgress(completed, total);
                processNext();
            }).fail(function () {
                completed++;
                failed++;
                row.find('.ai-status').html('<span style="color:red;">❌ Failed</span>');
                updateProgress(completed, total);
                processNext();
            });
        }

        function updateProgress(done, total) {
            var pct = Math.round((done / total) * 100);
            $('.ai-progress-bar').css('width', pct + '%').text(pct + '%');
            $('#ai-bulk-status').text('Processing ' + done + ' of ' + total + '...');
        }

        processNext();
    });

})(jQuery);
