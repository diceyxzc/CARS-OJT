<?php
function getStatusDisplay($status) {
    $display = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'completed' => 'Completed',
        'in_progress' => 'In Progress',
        'cancelled' => 'Cancelled'
    ];
    return $display[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function getStatusBadgeClass($status) {
    $classes = [
        'pending' => 'badge-pending',
        'approved' => 'badge-approved',
        'declined' => 'badge-declined',
        'completed' => 'badge-completed',
        'in_progress' => 'badge-in_progress',
        'cancelled' => 'badge-cancelled'
    ];
    return $classes[$status] ?? 'badge-pending';
}