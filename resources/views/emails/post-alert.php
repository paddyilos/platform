<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>iReport Liberia alert</title>
</head>
<body style="font-family: Arial, sans-serif; color: #222;">
    <p>A new report was published near your subscribed location on iReport Liberia:</p>

    <?php if (!empty($title)): ?>
        <h2 style="margin-bottom: 4px;"><?php echo htmlspecialchars($title); ?></h2>
    <?php endif; ?>

    <?php if (!empty($excerpt)): ?>
        <p><?php echo htmlspecialchars($excerpt); ?></p>
    <?php endif; ?>

    <?php if (!empty($report_url)): ?>
        <p><a href="<?php echo htmlspecialchars($report_url); ?>">View the full report</a></p>
    <?php endif; ?>

    <hr style="margin: 24px 0; border: none; border-top: 1px solid #ddd;">

    <p style="font-size: 12px; color: #666;">
        You're receiving this because you subscribed to iReport Liberia alerts for this
        area. <a href="<?php echo htmlspecialchars($unsubscribe_url); ?>">Unsubscribe</a> at any time.
    </p>
</body>
</html>
