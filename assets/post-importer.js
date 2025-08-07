jQuery(document).ready(function($) {
    let currentSessionId = null;
    let importRunning = false;
    let importPaused = false;
    let isReimporting = false;
    let selectedMediaFile = null; // Add this new variable

    // Media Library Selection
    $('#select-media-json').on('click', function(e) {
        e.preventDefault();
        
        // Check if wp.media is available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            alert('WordPress media library is not available. Please refresh the page and try again.');
            return;
        }
        
        console.log('Opening media library...');
        
        // Create media frame
        const mediaFrame = wp.media({
            title: 'Select JSON File',
            button: {
                text: 'Use This File'
            },
            multiple: false,
            library: {
                type: ['application/json', 'text/plain'] // Accept both JSON and text files
            }
        });
        
        // When file is selected
        mediaFrame.on('select', function() {
            const attachment = mediaFrame.state().get('selection').first().toJSON();
            
            console.log('Selected file:', attachment);
            
            // Validate file type (be more flexible)
            if (attachment.subtype !== 'json' && 
                attachment.mime !== 'application/json' && 
                attachment.mime !== 'text/plain' &&
                !attachment.filename.toLowerCase().endsWith('.json')) {
                alert('Please select a JSON file');
                return;
            }
            
            // Store selected file info
            selectedMediaFile = attachment;
            $('#media-file-id').val(attachment.id);
            
            // Display file info
            $('#selected-filename').text(attachment.filename || attachment.title);
            $('#selected-filesize').text(formatFileSize(attachment.filesizeInBytes || 0));
            $('#selected-date').text(new Date(attachment.date).toLocaleDateString());
            $('#selected-media-info').show();
            
            // Clear other input methods
            $('#json-file').val('');
            $('#file-path').val('');
            
            console.log('Media file selected successfully:', attachment.filename);
        });
        
        // Open media frame
        mediaFrame.open();
    });
    
    // Clear media selection
    $('#clear-media-selection').on('click', function() {
        selectedMediaFile = null;
        $('#media-file-id').val('');
        $('#selected-media-info').hide();
        console.log('Media selection cleared');
    });
    
    // Helper function to format file size
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    // File upload form submission
    $('#upload-form').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData();
        const fileInput = $('#json-file')[0];
        const filePath = $('#file-path').val().trim();
        const mediaFileId = $('#media-file-id').val();
        
        // Check which method is being used
        if (fileInput.files.length > 0) {
            // Method 1: File upload
            formData.append('json_file', fileInput.files[0]);
            console.log('Using file upload method');
        } else if (mediaFileId) {
            // Method 2: Media library selection
            formData.append('media_file_id', mediaFileId);
            console.log('Using media library method, file ID:', mediaFileId);
        } else if (filePath) {
            // Method 3: Server file path
            formData.append('file_path', filePath);
            console.log('Using server file path method');
        } else {
            alert('Please select a file using one of the three methods');
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
                $('#upload-form input[type="submit"]').prop('disabled', true).val('Analyzing...');
            },
            success: function(response) {
                console.log('Upload response:', response);
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
                    console.error('Upload error:', response.data);
                }
            },
            error: function(xhr, status, error) {
                alert('Analysis failed. Please try again.');
                console.error('AJAX error:', error, xhr.responseText);
            },
            complete: function() {
                $('#upload-form input[type="submit"]').prop('disabled', false).val('Analyze Selected File');
            }
        });
    });

    // Display import info function
    function displayImportInfo(data) {
        let html = `
            <p><strong>Total Posts:</strong> ${data.total_posts}</p>
            <p><strong>File:</strong> ${data.file_path}</p>
            <p><strong>Session ID:</strong> ${data.session_id}</p>
        `;
        
        if (data.file_size) {
            html += `<p><strong>File Size:</strong> ${data.file_size}</p>`;
        }
        
        html += `
            <div class="notice notice-info inline">
                <p><strong>Date Handling:</strong> Posts will be published with their original dates from the JSON file. 
                On reimport, publish dates are preserved but WordPress will update the modified date to current time.</p>
            </div>
        `;
        
        $('#import-info').html(html);
        updateProgress(0, data.total_posts, 0);
    }

    // Progress update function
    function updateProgress(processed, total, percentage) {
        $('#progress-fill').css('width', percentage + '%');
        $('#progress-text').text(Math.round(percentage) + '%');
        $('#import-stats').html(`<p>Processed: ${processed} / ${total}</p>`);
    }

    // Start import
    $('#start-import').on('click', function() {
        if (!currentSessionId) {
            alert('No import session found');
            return;
        }
        
        startImport();
    });
    
    // Start import function
    function startImport() {
        importRunning = true;
        isReimporting = false;
        $('#start-import').hide();
        $('#pause-import').show();
        $('#import-log').html('');
        $('#log-section').show();
        
        processNextBatch();
    }
    
    // Process batch function
    function processNextBatch() {
        if (!importRunning || importPaused) {
            return;
        }
        
        const action = isReimporting ? 'reimport_posts_batch' : 'import_posts_batch';
        
        $.ajax({
            url: postImporter.ajax_url,
            type: 'POST',
            data: {
                action: action,
                session_id: currentSessionId,
                batch_size: postImporter.batch_size,
                nonce: postImporter.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    updateProgress(data.total_processed, data.total_posts, data.percentage);
                    
                    // Add to log
                    const logEntry = `<p>Batch completed: ${data.imported} imported, ${data.failed} failed, ${data.skipped} skipped</p>`;
                    $('#import-log').append(logEntry);
                    
                    if (data.status === 'completed') {
                        importRunning = false;
                        $('#pause-import').hide();
                        $('#resume-import').hide();
                        alert('Import completed!');
                    } else {
                        // Continue with next batch
                        setTimeout(processNextBatch, 1000);
                    }
                } else {
                    alert('Batch failed: ' + response.data);
                    importRunning = false;
                }
            },
            error: function() {
                alert('Network error during import');
                importRunning = false;
            }
        });
    }

    // Pause import
    $('#pause-import').on('click', function() {
        importPaused = true;
        $('#pause-import').hide();
        $('#resume-import').show();
    });

    // Resume import
    $('#resume-import').on('click', function() {
        importPaused = false;
        $('#resume-import').hide();
        $('#pause-import').show();
        processNextBatch();
    });

    // Reimport posts
    $('#reimport-posts').on('click', function() {
        if (!currentSessionId) {
            alert('No import session found');
            return;
        }
        
        if (confirm('This will update existing posts and replace their content and images. Continue?')) {
            isReimporting = true;
            startImport();
        }
    });

    // Reset import
    $('#reset-import').on('click', function() {
        if (!currentSessionId) {
            alert('No import session found');
            return;
        }
        
        if (confirm('This will reset the import progress. Continue?')) {
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
                        alert('Import reset successfully');
                        location.reload();
                    } else {
                        alert('Reset failed: ' + response.data);
                    }
                }
            });
        }
    });
});
