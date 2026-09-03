  </main>

  <footer class="footer">
    <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
  </footer>
</div>
<script src="<?= APP_URL ?>/assets/js/main.js"></script>
<?php foreach ($extraScripts ?? [] as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
