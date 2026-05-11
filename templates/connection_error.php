<div style="max-width:600px;margin:40px auto;text-align:center;">
    <div style="margin-bottom:12px;"><?= icon("database", 40) ?></div>
    <h2 class="section-title" style="color:var(--danger);font-size:var(--font-size-xl);">Connection Failed</h2>
    <div class="error-box" style="text-align:left;margin:20px 0;">
        <strong>Could not connect to MySQL:</strong><br><br>
        <?= h($connectionError ?? 'Unknown error') ?>
    </div>
    <div class="info-card" style="text-align:left;margin-top:20px;">
        <div class="info-label">Troubleshooting</div>
        <div style="margin-top:8px;font-size:var(--font-size-sm);color:var(--text-secondary);line-height:1.8;">
            1. Make sure XAMPP's MySQL service is running<br>
            2. Check the credentials in <code style="color:var(--accent);">config.php</code><br>
            3. Default XAMPP: host = <code style="color:var(--info);">127.0.0.1</code>, user = <code style="color:var(--info);">root</code>, password = <code style="color:var(--info);">(empty)</code><br>
            4. Check that port <code style="color:var(--info);">3306</code> matches your MySQL config
        </div>
    </div>
    <div style="margin-top:20px;">
        <a href="?" class="btn btn-primary">↻ Try Again</a>
    </div>
</div>
