jQuery(document).ready(function($) {
    let currentSessionId = null;
    let importRunning = false;
    let importPaused = false;
    let isReimporting = false;
    
    // File upload form submission
    $('#upload-form').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData();
        const fileInput = $('#json-file')[0];
        const filePath = $('#file-path').val();
        
        if (fileInput.files.length > 0) {
            formData.append('json_file', fileInput.files[0]);
        } else if (filePath) {
            formData.append('file_path', filePath);
        } else {
            alert('Please select a file or enter a file path');
            return;
        }
        
        formData.append('action', 'upload_json_file');
        formData.append('nonce', postImporter.nonce);
        
        $.ajax({
            url: postImporter.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            beforeSend: function() {
                $('#upload-form input[type="submit"]').prop('disabled', true).val('Uploading...');
            },
            success: function(response) {
                if (response.success) {
                    currentSessionId = response.data.session_id;
                    displayImportInfo(response.data);
                    $('#status-section').show();
                    
                    // Show all control buttons when file is analyzed
                    $('#start-import').show();
                    $('#reimport-posts').show();
                    $('#reset-import').show();
                } else {
                    alert('Error: ' + response.data);
                }
            },
            error: function() {
                alert('Upload failed. Please try again.');
            },
            complete: function() {
                $('#upload-form input[type="submit"]').prop('disabled', false).val('Upload & Analyze File');
            }
        });
    });
    
    // Start import
    $('#start-import').on('click', function() {
        if (!currentSessionId) {
            alert('No import session found');
            return;
        }
        
        startImport();
    });
    
    // Pause import
    $('#pause-import').on('click', function() {
        importPaused = true;
        $('#pause-import').hide();
        $('#resume-import').show();
        logMessage('Import paused');
    });
    
    // Resume import
    $('#resume-import').on('click', function() {
        importPaused = false;
        $('#resume-import').hide();
        $('#pause-import').show();
        logMessage('Import resumed');
        continueImport();
    });
    
    // Reset import
    $('#reset-import').on('click', function() {
        if (!currentSessionId) {
            alert('No import session found');
            return;
        }
        
        if (confirm('Are you sure you want to reset the import? This will start over from the beginning.')) {
            resetImport();
        }
    });
    
    // Reimport posts
    $('#reimport-posts').on('click', function() {
        if (!currentSessionId) {
            alert('No import session found');
            return;
        }
        
        if (confirm('Are you sure you want to reimport posts? This will replace existing posts and their featured images with the content from the JSON file.')) {
            startReimport();
        }
    });
    
    function displayImportInfo(data) {
        const html = `
            <p><strong>Total Posts:</strong> ${data.total_posts}</p>
            <p><strong>File:</strong> ${data.file_path}</p>
            <p><strong>Session ID:</strong> ${data.session_id}</p>
        `;
        $('#import-info').html(html);
        updateProgress(0, data.total_posts, 0);
    }
    
    function startImport() {
        importRunning = true;
        importPaused = false;
        
        $('#start-import').hide();
        $('#pause-import').show();
        $('#log-section').show();
        
        logMessage('Starting import process...');
        continueImport();
    }
    
    function startReimport() {
        importRunning = true;
        importPaused = false;
        
        $('#start-import').hide();
        $('#reimport-posts').hide();
        $('#pause-import').show();
        $('#log-section').show();
        
        logMessage('Starting reimport process (will replace existing posts and images)...');
        continueReimport();
    }
    
    function continueImport() {
        if (!importRunning || importPaused) {
            return;
        }
        
        $.ajax({
            url: postImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'import_posts_batch',
                session_id: currentSessionId,
                batch_size: postImporter.batch_size,
                nonce: postImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    updateProgress(data.total_processed, data.total_posts, data.percentage);
                    updateStats(data);
                    
                    const statusMsg = `Batch completed: ${data.imported} imported, ${data.failed} failed, ${data.skipped} skipped`;
                    logMessage(statusMsg);
                    
                    // Log more details if available
                    if (data.imported > 0) {
                        logMessage(`✓ Successfully imported ${data.imported} posts with content and featured images`, 'success');
                    }
                    if (data.failed > 0) {
                        logMessage(`✗ Failed to import ${data.failed} posts - check WordPress error logs for details`, 'error');
                    }
                    if (data.skipped > 0) {
                        logMessage(`⚠ Skipped ${data.skipped} posts (already exist)`, 'info');
                    }
                    
                    if (data.status === 'completed') {
                        importCompleted();
                    } else if (!importPaused) {
                        // Continue with next batch after a short delay
                        setTimeout(continueImport, 500);
                    }
                } else {
                    logMessage('Error: ' + response.data, 'error');
                    importRunning = false;
                    $('#pause-import').hide();
                    $('#start-import').show();
                }
            },
            error: function() {
                logMessage('AJAX error occurred. Retrying in 5 seconds...', 'error');
                setTimeout(continueImport, 5000);
            }
        });
    }
    
    function continueReimport() {
        if (!importRunning || importPaused) {
            return;
        }
        
        $.ajax({
            url: postImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'reimport_posts_batch',
                session_id: currentSessionId,
                batch_size: postImporter.batch_size,
                nonce: postImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    updateProgress(data.total_processed, data.total_posts, data.percentage);
                    updateStats(data);
                    
                    const statusMsg = `Reimport batch completed: ${data.imported} reimported, ${data.failed} failed, ${data.skipped} skipped`;
                    logMessage(statusMsg);
                    
                    // Log more details if available
                    if (data.imported > 0) {
                        logMessage(`✓ Successfully reimported ${data.imported} posts with updated content and featured images`, 'success');
                    }
                    if (data.failed > 0) {
                        logMessage(`✗ Failed to reimport ${data.failed} posts - check WordPress error logs for details`, 'error');
                    }
                    if (data.skipped > 0) {
                        logMessage(`⚠ Skipped ${data.skipped} posts (no existing post found, imported as new)`, 'info');
                    }
                    
                    if (data.status === 'completed') {
                        reimportCompleted();
                    } else if (!importPaused) {
                        // Continue with next batch after a short delay
                        setTimeout(continueReimport, 500);
                    }
                } else {
                    logMessage('Error: ' + response.data, 'error');
                    importRunning = false;
                    $('#pause-import').hide();
                    $('#start-import').show();
                    $('#reimport-posts').show();
                }
            },
            error: function() {
                logMessage('AJAX error occurred. Retrying in 5 seconds...', 'error');
                setTimeout(continueReimport, 5000);
            }
        });
    }
    
    function updateProgress(processed, total, percentage) {
        $('#progress-fill').css('width', percentage + '%');
        $('#progress-text').text(percentage + '% (' + processed + '/' + total + ')');
    }
    
    function updateStats(data) {
        const html = `
            <p><strong>Imported:</strong> ${data.total_processed - data.failed}</p>
            <p><strong>Failed:</strong> ${data.failed || 0}</p>
            <p><strong>Remaining:</strong> ${data.total_posts - data.total_processed}</p>
        `;
        $('#import-stats').html(html);
    }
    
    function importCompleted() {
        importRunning = false;
        $('#pause-import').hide();
        $('#resume-import').hide();
        $('#start-import').show();
        
        logMessage('Import completed successfully!', 'success');
        
        // Show completion summary
        getImportStatus();
    }
    
    function reimportCompleted() {
        importRunning = false;
        $('#pause-import').hide();
        $('#resume-import').hide();
        $('#start-import').show();
        $('#reimport-posts').show();
        
        logMessage('Reimport completed successfully! All posts and featured images have been updated.', 'success');
        
        // Show completion summary
        getImportStatus();
    }
    
    function resetImport() {
        $.ajax({
            url: postImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'reset_import',
                session_id: currentSessionId,
                nonce: postImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    importRunning = false;
                    importPaused = false;
                    
                    $('#pause-import').hide();
                    $('#resume-import').hide();
                    $('#start-import').show();
                    
                    updateProgress(0, 0, 0);
                    $('#import-stats').html('');
                    $('#import-log').html('');
                    
                    logMessage('Import reset successfully');
                } else {
                    alert('Reset failed: ' + response.data);
                }
            },
            error: function() {
                alert('Reset failed. Please try again.');
            }
        });
    }
    
    function getImportStatus() {
        if (!currentSessionId) return;
        
        $.ajax({
            url: postImporter.ajax_url,
            type: 'POST',
            data: {
                action: 'get_import_status',
                session_id: currentSessionId,
                nonce: postImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    updateProgress(data.processed_posts, data.total_posts, data.percentage);
                    updateStats({
                        total_processed: data.processed_posts,
                        failed: data.failed_posts,
                        total_posts: data.total_posts
                    });
                }
            }
        });
    }
    
    function logMessage(message, type = 'info') {
        const timestamp = new Date().toLocaleTimeString();
        const cssClass = type === 'error' ? 'error' : (type === 'success' ? 'success' : 'info');
        
        const logEntry = `<div class="log-entry ${cssClass}">
            <span class="timestamp">[${timestamp}]</span>
            <span class="message">${message}</span>
        </div>`;
        
        $('#import-log').append(logEntry);
        $('#import-log').scrollTop($('#import-log')[0].scrollHeight);
    }
    
    // Auto-refresh status every 30 seconds if import is running
    setInterval(function() {
        if (importRunning && !importPaused) {
            getImportStatus();
        }
    }, 30000);
});
