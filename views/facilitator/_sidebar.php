<?php
// File: views/facilitator/_sidebar.php

// Ensure these variables are passed from the including file:
$currentPage = $currentPage ?? basename($_SERVER['PHP_SELF']);
$currentStatus = $currentStatus ?? '';

// Array of sidebar links
$sidebarLinks = [
    ['label' => 'Dashboard Home', 'icon' => 'bi-house-door', 'href' => 'dashboard.php', 'match_file' => 'dashboard.php', 'status' => ''],
    ['label' => 'Create Researcher Account', 'icon' => 'bi-person-plus', 'href' => 'faci_register.php', 'match_file' => 'faci_register.php', 'status' => ''],
    ['label' => 'View Researchers', 'icon' => 'bi-people', 'href' => 'researchers.php', 'match_file' => 'researchers.php', 'status' => ''],
    ['label' => 'Submit Work for Researcher', 'icon' => 'bi-upload', 'href' => 'submit.php', 'match_file' => 'submit.php', 'status' => ''],
    ['label' => 'Track Single Submission', 'icon' => 'bi-search', 'href' => 'track_submission.php', 'match_file' => 'track_submission.php', 'status' => ''],
    // Manage Submissions section
    ['label' => 'Submissions for Ref. Number Generation', 'icon' => 'bi-inbox', 'href' => 'manage_submissions.php?status=submitted', 'match_file' => 'manage_submissions.php', 'status' => 'submitted'], // Renamed
    // 'Pending Review' link removed as requested
    ['label' => 'Accepted Submissions', 'icon' => 'bi-check-circle', 'href' => 'manage_submissions.php?status=accepted_by_facilitator', 'match_file' => 'manage_submissions.php', 'status' => 'accepted_by_facilitator'],
    ['label' => 'Forwarded to PIO', 'icon' => 'bi-send', 'href' => 'manage_submissions.php?status=forwarded_to_pio', 'match_file' => 'manage_submissions.php', 'status' => 'forwarded_to_pio'],
    ['label' => 'Rejected Submissions', 'icon' => 'bi-x-circle', 'href' => 'manage_submissions.php?status=rejected', 'match_file' => 'manage_submissions.php', 'status' => 'rejected'],
    // 'Revision Requested' link removed as requested
    // 'All Submissions' link removed as requested
];

// Special handling for 'view_submission.php' to keep 'Submissions for Ref. Number Generation' active if coming from there
$isViewSubmissionPage = ($currentPage === 'view_submission.php');
?>
<div id="sidebar">
    <h5 class="mb-3">Navigation</h5>
    <ul class="nav nav-pills flex-column mb-auto">
        <?php foreach ($sidebarLinks as $link): ?>
            <?php
                $isActive = false;
                if ($currentPage === $link['match_file']) {
                    if ($link['match_file'] === 'manage_submissions.php') {
                        if (($currentStatus === '' && $link['status'] === '') || ($currentStatus === $link['status'])) {
                            $isActive = true;
                        }
                    } else if ($currentPage === $link['match_file']) {
                        $isActive = true;
                    }
                }
                // If on view_submission.php, and it's a "new" submission (status=submitted), highlight the ref generation link
                if ($isViewSubmissionPage && $link['label'] === 'Submissions for Ref. Number Generation' && isset($_GET['id'])) {
                    // This would require fetching the submission status to be truly accurate.
                    // For simplicity, we'll just assume if you are viewing a submission,
                    // the most relevant "manage" link is the one it originated from.
                    // Or, if no status is explicitly passed to view_submission, default to highlighting 'New Submissions'
                    // This is a complex state management for a sidebar, often handled by passing more context.
                    // For now, if view_submission, we don't activate any 'manage_submissions' link by default
                    // unless a specific status is matched.
                    // The primary dashboard link will remain active if no specific status is matched.
                }

                // Default to highlight Dashboard Home if no specific match
                if (!$isActive && $link['label'] === 'Dashboard Home' && empty($currentStatus) && !$isViewSubmissionPage) {
                    $isActive = true;
                }
            ?>
            <li class="nav-item">
                <a href="<?= htmlspecialchars($link['href']) ?>" class="nav-link <?= $isActive ? 'active' : '' ?>">
                    <i class="bi <?= htmlspecialchars($link['icon']) ?> me-2"></i>
                    <?= htmlspecialchars($link['label']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
