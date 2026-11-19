<?php
$hasRows = $applications !== [];
$emptyHidden = $hasRows || $error !== null;
$errorHidden = $error === null;
?>
<tbody id="activity-rows" data-activity-rows><?php foreach ($applications as $application): ?><?= view('partials/activity_row', ['application' => $application, 'canManage' => $canManage]) ?><?php endforeach ?></tbody>
<p id="activity-empty" class="notification is-light<?= $emptyHidden ? ' is-hidden' : '' ?>" data-activity-empty role="status" hx-swap-oob="true">No application containers or operations to show.</p>
<p id="activity-error" class="notification is-danger is-light<?= $errorHidden ? ' is-hidden' : '' ?>" data-activity-error role="alert" hx-swap-oob="true"><?= esc($error ?? '') ?></p>
