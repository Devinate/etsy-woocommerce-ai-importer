(function($) {
    'use strict';

    $(document).ready(function() {
        var $form = $('#etsy-import-form');
        var $progress = $('#import-progress');
        var $results = $('#import-results');
        var $log = $('#import-log');
        var $progressBar = $('.progress-bar');
        var $progressText = $('.progress-text');
        var eventSource = null;

        // AI Settings Form Handler
        $('#etsy-ai-settings-form').on('submit', function(e) {
            e.preventDefault();

            var $status = $('#ai-settings-status');
            var $button = $(this).find('button[type="submit"]');

            $button.prop('disabled', true).text('Saving...');
            $status.text('');

            var formData = new FormData();
            formData.append('action', 'etsy_save_settings');
            formData.append('nonce', etsyImporter.nonce);
            formData.append('hf_api_token', $('#hf-api-token').val());
            formData.append('use_ai_categorization', $('input[name="use_ai_categorization"]').is(':checked') ? '1' : '0');

            $.ajax({
                url: etsyImporter.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $status.css('color', 'green').text('✓ Settings saved');
                    } else {
                        $status.css('color', 'red').text('✗ ' + (response.data.message || 'Save failed'));
                    }
                },
                error: function() {
                    $status.css('color', 'red').text('✗ Save failed');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Save AI Settings');
                }
            });
        });

        $form.on('submit', function(e) {
            e.preventDefault();

            var fileInput = $('#etsy-csv-file')[0];
            if (!fileInput.files.length) {
                alert('Please select a CSV file to import.');
                return;
            }

            var file = fileInput.files[0];
            if (!file.name.endsWith('.csv')) {
                alert('Please select a valid CSV file.');
                return;
            }

            // Prepare form data
            var formData = new FormData();
            formData.append('action', 'etsy_import_products');
            formData.append('nonce', etsyImporter.nonce);
            formData.append('csv_file', file);
            formData.append('import_images', $('input[name="import_images"]').is(':checked') ? '1' : '0');
            formData.append('mark_digital', $('input[name="mark_digital"]').is(':checked') ? '1' : '0');
            formData.append('draft_status', $('input[name="draft_status"]').is(':checked') ? '1' : '0');
            formData.append('import_categories', $('input[name="import_categories"]').is(':checked') ? '1' : '0');
            formData.append('skip_ai_categorized', $('input[name="skip_ai_categorized"]').is(':checked') ? '1' : '0');
            formData.append('create_categories', $('input[name="create_categories"]').is(':checked') ? '1' : '0');
            formData.append('default_category', $('#default-category').val());

            // Show progress
            $form.find('button[type="submit"]').prop('disabled', true).text('Importing...');
            $progress.show();
            $results.hide();
            $log.empty();

            addLog('Starting import...', 'info');
            addLog('Uploading CSV file: ' + file.name, 'info');
            updateProgress(5, 'Uploading file...');

            // First upload the file and get stream URL
            $.ajax({
                url: etsyImporter.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    var xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            var percent = Math.round((e.loaded / e.total) * 10);
                            updateProgress(percent, 'Uploading file...');
                        }
                    });
                    return xhr;
                },
                success: function(response) {
                    if (response.success && response.data.stream_url) {
                        addLog('File uploaded, starting import...', 'info');
                        updateProgress(15, 'Processing products...');

                        // Start SSE connection for real-time updates
                        startStreamingImport(response.data.stream_url);
                    } else {
                        updateProgress(0, 'Import failed');
                        addLog('Error: ' + (response.data.message || 'Unknown error'), 'error');
                        $form.find('button[type="submit"]').prop('disabled', false).text('Start Import');
                    }
                },
                error: function(xhr, status, error) {
                    updateProgress(0, 'Upload failed');
                    addLog('Upload Error: ' + error, 'error');
                    $form.find('button[type="submit"]').prop('disabled', false).text('Start Import');
                }
            });
        });

        function startStreamingImport(streamUrl) {
            // Close any existing connection
            if (eventSource) {
                eventSource.close();
            }

            eventSource = new EventSource(streamUrl);

            eventSource.addEventListener('log', function(e) {
                var data = JSON.parse(e.data);
                var prefix = '';

                if (data.type === 'ai') {
                    prefix = '🤖 ';
                } else if (data.type === 'success') {
                    prefix = '✓ ';
                } else if (data.type === 'warning') {
                    prefix = '⚠ ';
                } else if (data.type === 'error') {
                    prefix = '✗ ';
                } else {
                    prefix = '→ ';
                }

                addLog(prefix + data.message, data.type);
            });

            eventSource.addEventListener('progress', function(e) {
                var data = JSON.parse(e.data);
                // Map 0-100 to 15-95 (15% reserved for upload, 5% for completion)
                var displayPercent = 15 + Math.round(data.percent * 0.8);
                updateProgress(displayPercent, 'Processing ' + data.current + ' of ' + data.total + '...');
            });

            eventSource.addEventListener('batch_info', function(e) {
                var data = JSON.parse(e.data);
                if (data.enabled) {
                    showBatchInfo(data.batch_size, data.total_batches);
                }
            });

            eventSource.addEventListener('batch_progress', function(e) {
                var data = JSON.parse(e.data);
                updateBatchProgress(data.current_batch, data.total_batches);
            });

            eventSource.addEventListener('error', function(e) {
                var data = JSON.parse(e.data);
                addLog('Error: ' + data.message, 'error');
            });

            eventSource.addEventListener('complete', function(e) {
                var data = JSON.parse(e.data);

                eventSource.close();
                eventSource = null;

                updateProgress(100, 'Import complete!');
                showResults(data);
                $form.find('button[type="submit"]').prop('disabled', false).text('Start Import');
            });

            eventSource.onerror = function(e) {
                // Only handle if not already closed
                if (eventSource && eventSource.readyState === EventSource.CLOSED) {
                    return;
                }

                addLog('Connection error - import may have completed', 'warning');
                if (eventSource) {
                    eventSource.close();
                    eventSource = null;
                }
                $form.find('button[type="submit"]').prop('disabled', false).text('Start Import');
            };
        }

        function updateProgress(percent, text) {
            $progressBar.css('width', percent + '%');
            $progressText.text(text);
        }

        function addLog(message, type) {
            var $entry = $('<div class="log-entry"></div>');
            $entry.addClass('log-' + type);
            $entry.text('[' + new Date().toLocaleTimeString() + '] ' + message);
            $log.append($entry);
            $log.scrollTop($log[0].scrollHeight);
        }

        function showBatchInfo(batchSize, totalBatches) {
            var $batchInfo = $('#ai-batch-info');
            if (!$batchInfo.length) {
                $batchInfo = $('<div id="ai-batch-info" class="ai-batch-notice"></div>');
                $progress.find('h3').after($batchInfo);
            }
            $batchInfo.html(
                '<span class="dashicons dashicons-info"></span> ' +
                '<strong>AI Categorization:</strong> Processing ' + totalBatches + ' batches of ~' + batchSize + ' products each. ' +
                '<span class="batch-status">Starting...</span>'
            );
            $batchInfo.show();
        }

        function updateBatchProgress(currentBatch, totalBatches) {
            var $batchStatus = $('#ai-batch-info .batch-status');
            if ($batchStatus.length) {
                $batchStatus.text('Batch ' + currentBatch + ' of ' + totalBatches);
            }
        }

        function showResults(data) {
            var html = '<div class="stat stat-success">' +
                '<span class="stat-number">' + data.imported + '</span>' +
                '<span class="stat-label">Imported</span>' +
                '</div>';

            if (data.updated > 0) {
                html += '<div class="stat stat-info">' +
                    '<span class="stat-number">' + data.updated + '</span>' +
                    '<span class="stat-label">Updated</span>' +
                    '</div>';
            }

            if (data.categories_created > 0) {
                html += '<div class="stat stat-success">' +
                    '<span class="stat-number">' + data.categories_created + '</span>' +
                    '<span class="stat-label">Categories Created</span>' +
                    '</div>';
            }

            if (data.images_queued > 0) {
                html += '<div class="stat stat-info">' +
                    '<span class="stat-number">' + data.images_queued + '</span>' +
                    '<span class="stat-label">Images Queued</span>' +
                    '</div>';
            }

            if (data.skipped > 0) {
                html += '<div class="stat">' +
                    '<span class="stat-number">' + data.skipped + '</span>' +
                    '<span class="stat-label">Skipped</span>' +
                    '</div>';
            }

            if (data.errors && data.errors.length > 0) {
                html += '<div class="stat stat-error">' +
                    '<span class="stat-number">' + data.errors.length + '</span>' +
                    '<span class="stat-label">Errors</span>' +
                    '</div>';
            }

            if (data.duration) {
                html += '<div class="stat">' +
                    '<span class="stat-number" style="font-size: 24px;">' + data.duration + '</span>' +
                    '<span class="stat-label">Duration</span>' +
                    '</div>';
            }

            if (data.imported > 0 || data.updated > 0) {
                html += '<p style="margin-top: 20px;">' +
                    '<a href="edit.php?post_type=product" class="button">View Products</a>' +
                    '</p>';
            }

            if (data.images_queued > 0) {
                html += '<p class="description" style="margin-top: 10px;">Images are being downloaded in the background. Refresh the page to see progress.</p>';
            }

            $results.find('.results-summary').html(html);
            $results.show();
        }

        // Toggle missing products list
        $('#toggle-missing-list').on('click', function() {
            $('#missing-products-list').slideToggle();
        });

        // Download product titles as CSV
        $('#download-titles-csv').on('click', function() {
            var titles = [];
            $('#missing-products-list tbody tr').each(function() {
                var title = $(this).find('code').text();
                if (title) {
                    titles.push(title);
                }
            });

            if (titles.length === 0) {
                alert('No products to export');
                return;
            }

            // Create CSV content
            var csv = 'TITLE,URL\n';
            titles.forEach(function(title) {
                // Escape quotes in title
                var escapedTitle = '"' + title.replace(/"/g, '""') + '"';
                csv += escapedTitle + ',\n';
            });

            // Download
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            var url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'etsy_url_mapping_template.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });

        // Bulk URL Update Form Handler
        $('#etsy-bulk-url-form').on('submit', function(e) {
            e.preventDefault();

            var $status = $('#bulk-url-status');
            var $button = $(this).find('button[type="submit"]');
            var $results = $('#bulk-url-results');
            var fileInput = $('#etsy-url-csv')[0];

            if (!fileInput.files.length) {
                $status.css('color', 'red').text('Please select a CSV file.');
                return;
            }

            var file = fileInput.files[0];
            if (!file.name.endsWith('.csv')) {
                $status.css('color', 'red').text('Please select a valid CSV file.');
                return;
            }

            $button.prop('disabled', true).text('Processing...');
            $status.css('color', '#666').text('Matching products...');
            $results.hide();

            var formData = new FormData();
            formData.append('action', 'etsy_bulk_update_urls');
            formData.append('nonce', etsyImporter.nonce);
            formData.append('url_csv_file', file);
            formData.append('etsy_shop_name', $('#etsy-shop-name').val());

            $.ajax({
                url: etsyImporter.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        $status.css('color', 'green').text('✓ ' + response.data.message);

                        var html = '<div class="stat stat-success">' +
                            '<span class="stat-number">' + response.data.updated + '</span>' +
                            '<span class="stat-label">Updated</span>' +
                            '</div>';

                        if (response.data.skipped > 0) {
                            html += '<div class="stat">' +
                                '<span class="stat-number">' + response.data.skipped + '</span>' +
                                '<span class="stat-label">Skipped</span>' +
                                '</div>';
                        }

                        if (response.data.notfound > 0) {
                            html += '<div class="stat stat-warning">' +
                                '<span class="stat-number">' + response.data.notfound + '</span>' +
                                '<span class="stat-label">Not Found</span>' +
                                '</div>';
                        }

                        if (response.data.errors && response.data.errors.length > 0) {
                            html += '<div class="unmatched-list"><strong>Sample unmatched titles:</strong><ul>';
                            response.data.errors.forEach(function(err) {
                                html += '<li>' + err + '</li>';
                            });
                            html += '</ul></div>';
                        }

                        $results.find('.results-summary').html(html);
                        $results.show();

                        // Reload the page after 2 seconds to update the missing count
                        setTimeout(function() {
                            location.reload();
                        }, 3000);
                    } else {
                        $status.css('color', 'red').text('✗ ' + (response.data.message || 'Update failed'));
                    }
                },
                error: function() {
                    $status.css('color', 'red').text('✗ Request failed');
                },
                complete: function() {
                    $button.prop('disabled', false).text('Update Etsy URLs');
                }
            });
        });
    });
})(jQuery);
