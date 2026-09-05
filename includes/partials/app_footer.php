    </main>

    <footer class="app-footer">
      <p>&copy; <?= date('Y') ?> MBC Media Group. All rights reserved.</p>
      <p>OJT Attendance Management System</p>
      <p>Version 1.0.0</p>
    </footer>
  </div>

  <?php if (!empty($navUser)): ?>
  <nav class="bottom-nav">
    <?php foreach ($bottomNavItems as $item): ?>
      <a class="bottom-nav-item<?= $activeNav === $item['key'] ? ' active' : '' ?>" href="<?= e($item['href']) ?>">
        <?= icon($item['icon']) ?>
        <span><?= e($item['label']) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</div>
<script src="<?= e(asset_url('/assets/js/main.js')) ?>"></script>
<?php foreach ($extraScripts ?? [] as $src): ?>
<script src="<?= e(asset_url($src)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
