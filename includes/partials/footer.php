  </main>

  <footer class="footer">
    <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
  </footer>
</div>
<script src="<?= e(asset_url('/assets/js/main.js')) ?>"></script>
<?php foreach ($extraScripts ?? [] as $src): ?>
<script src="<?= e(asset_url($src)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
