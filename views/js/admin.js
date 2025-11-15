/**
 * Odoo Sales Sync - Admin JavaScript
 *
 * @author Odoo Sales Sync Module
 * @version 1.0.0
 */

/**
 * Test Odoo connection
 * Matches reference module pattern from odoo_direct_stock_sync
 */
function testOdooConnection() {
    var $btn = $('#test-connection-btn');
    var $result = $('#test-connection-result');
    var originalText = $btn.html();

    $btn.html('<i class="icon-refresh icon-spin"></i> Testing...');
    $btn.prop('disabled', true);
    $result.html('');

    // Debug: Log request details (matching reference module)
    var requestUrl = window.location.href;
    var requestData = {
        ajax: 1,
        action: 'testConnection'
    };

    console.log('=== TEST CONNECTION DEBUG ===');
    console.log('Request URL:', requestUrl);
    console.log('Request Data:', requestData);

    $.ajax({
        url: requestUrl,
        type: 'POST',
        data: requestData,
        dataType: 'json',
        success: function(response) {
            console.log('AJAX Success - Response:', response);
            if (response.success) {
                $result.html('<span class="text-success"><i class="icon-check"></i> ' + response.message + '</span>');
            } else {
                $result.html('<span class="text-danger"><i class="icon-times"></i> ' + (response.message || 'Unknown error') + '</span>');
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            console.error('Response:', xhr.responseText);
            $result.html('<span class="text-danger"><i class="icon-times"></i> Connection test failed: ' + error + '</span>');
        },
        complete: function() {
            $btn.html(originalText);
            $btn.prop('disabled', false);
        }
    });

    return false;
}

/**
 * Retry failed events
 */
function retryFailedEvents() {
    if (!confirm('Retry all failed events?')) {
        return false;
    }

    var $btn = $('#retry-failed-btn');
    var originalText = $btn.html();

    $btn.html('<i class="icon-refresh icon-spin"></i> Retrying...');
    $btn.prop('disabled', true);

    $.ajax({
        url: ajaxUrl,
        type: 'POST',
        data: {
            ajax: 1,
            action: 'retryFailed',
            token: token
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert('Retry initiated successfully. Check the Events tab for results.');
                location.reload();
            } else {
                alert('Error: ' + (response.message || 'Failed to retry events'));
            }
        },
        error: function(xhr, status, error) {
            console.error('Retry error:', error);
            alert('Error: Failed to retry events');
        },
        complete: function() {
            $btn.html(originalText);
            $btn.prop('disabled', false);
        }
    });

    return false;
}

// ============================================================================
// Sales Events Tab Functions
// ============================================================================

/**
 * Refresh events table with current filters
 */
function refreshEvents() {
    var filterMinutes = $('#events_filter_minutes').val();
    var filterEntity = $('#events_filter_entity').val();
    var filterAction = $('#events_filter_action').val();
    var filterStatus = $('#events_filter_status').val();
    var limit = $('#events_limit').val();

    // Build URL with filters
    var url = window.location.href.split('?')[0] + '?';
    url += 'configure=odoo_sales_sync';
    url += '&active_tab=events';
    url += '&events_filter_minutes=' + filterMinutes;
    url += '&events_filter_entity=' + filterEntity;
    url += '&events_filter_action=' + filterAction;
    url += '&events_filter_status=' + filterStatus;
    url += '&events_limit=' + limit;

    window.location.href = url;
}

/**
 * Retry single event
 */
function retryEvent(eventId) {
    if (!confirm('Retry this event?')) {
        return false;
    }

    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: 1,
            action: 'retryEvent',
            event_id: eventId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert('Event queued for retry');
                refreshEvents();
            } else {
                alert('Error: ' + (response.error || 'Failed to retry event'));
            }
        },
        error: function(xhr, status, error) {
            console.error('Retry error:', error);
            alert('Error: Failed to retry event');
        }
    });

    return false;
}

/**
 * View event details in modal
 */
function viewEventDetails(eventId, entityType) {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: 1,
            action: 'viewEventDetails',
            event_id: eventId
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Create modal
                var modal = $('<div class="modal fade" id="event-detail-modal" tabindex="-1">');
                modal.html(
                    '<div class="modal-dialog modal-lg">' +
                    '<div class="modal-content">' +
                    '<div class="modal-header">' +
                    '<button type="button" class="close" data-dismiss="modal">&times;</button>' +
                    '<h4 class="modal-title">Event Details</h4>' +
                    '</div>' +
                    '<div class="modal-body">' + response.html + '</div>' +
                    '<div class="modal-footer">' +
                    '<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>' +
                    '</div>' +
                    '</div>' +
                    '</div>'
                );

                // Remove existing modal if present
                $('#event-detail-modal').remove();

                // Show modal
                $('body').append(modal);
                modal.modal('show');

                // Clean up on close
                modal.on('hidden.bs.modal', function() {
                    modal.remove();
                });
            } else {
                alert('Error: ' + (response.error || 'Failed to load event details'));
            }
        },
        error: function(xhr, status, error) {
            console.error('View details error:', error);
            alert('Error: Failed to load event details');
        }
    });

    return false;
}

/**
 * Bulk retry selected events
 */
function bulkRetryEvents() {
    var selectedIds = [];
    $('.event-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });

    if (selectedIds.length === 0) {
        alert('Please select at least one event');
        return false;
    }

    if (!confirm('Retry ' + selectedIds.length + ' selected events?')) {
        return false;
    }

    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: 1,
            action: 'bulkRetryEvents',
            event_ids: selectedIds
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message || 'Events queued for retry');
                refreshEvents();
            } else {
                alert('Error: ' + (response.error || 'Failed to retry events'));
            }
        },
        error: function(xhr, status, error) {
            console.error('Bulk retry error:', error);
            alert('Error: Failed to retry events');
        }
    });

    return false;
}

/**
 * Bulk mark events as sent
 */
function bulkMarkAsSent() {
    var selectedIds = [];
    $('.event-checkbox:checked').each(function() {
        selectedIds.push($(this).val());
    });

    if (selectedIds.length === 0) {
        alert('Please select at least one event');
        return false;
    }

    if (!confirm('Mark ' + selectedIds.length + ' selected events as sent?')) {
        return false;
    }

    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: 1,
            action: 'bulkMarkAsSent',
            event_ids: selectedIds
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                alert(response.message || 'Events marked as sent');
                refreshEvents();
            } else {
                alert('Error: ' + (response.error || 'Failed to mark events'));
            }
        },
        error: function(xhr, status, error) {
            console.error('Bulk mark error:', error);
            alert('Error: Failed to mark events');
        }
    });

    return false;
}

/**
 * Export events to CSV
 */
function exportEvents() {
    var filterMinutes = $('#events_filter_minutes').val();
    var filterEntity = $('#events_filter_entity').val();
    var filterAction = $('#events_filter_action').val();
    var filterStatus = $('#events_filter_status').val();

    // Build export URL with filters
    var url = window.location.href.split('?')[0] + '?';
    url += 'configure=odoo_sales_sync';
    url += '&ajax=1';
    url += '&action=exportEvents';
    url += '&events_filter_minutes=' + filterMinutes;
    url += '&events_filter_entity=' + filterEntity;
    url += '&events_filter_action=' + filterAction;
    url += '&events_filter_status=' + filterStatus;

    // Trigger download
    window.location.href = url;

    return false;
}

/**
 * Toggle all event checkboxes
 */
function toggleAllEvents(source) {
    $('.event-checkbox').prop('checked', source.checked);
}
