<!-- === CREATE GROUP MODAL (раздел 6.4) === -->
<div class="modal-overlay" id="createGroupModal">
  <div class="modal modal-max-md">
    <h3>👥 Создание группового чата</h3>
    <button class="icon-btn modal-close" id="closeGroupModal">✕</button>

    <p class="card-desc-lg">
      Создайте группу для общения нескольких пользователей сети через ваш узел.
    </p>

    <form action="api/create_group.php" method="POST" id="createGroupForm">
      <div class="field">
        <label for="groupTitle">Название группы *</label>
        <input
          type="text"
          id="groupTitle"
          name="title"
          required
          maxlength="64"
          placeholder="Например: Разработка проекта 101"
        >
      </div>

      <div class="field">
        <label>Добавить участников из контактов</label>
        <?php 
          $activeContacts = array_filter($contacts ?? [], fn($c) => ($c['status'] ?? 'accepted') === 'accepted');
        ?>
        <?php if (empty($activeContacts)): ?>
          <div class="member-select-hint">
            У вас пока нет подтверждённых контактов. Вы можете ввести имена пользователей ниже.
          </div>
        <?php else: ?>
          <div class="member-select-scroll">
            <?php foreach ($activeContacts as $c): ?>
              <?php 
                $targetUserId = (int)($c['contact_id'] ?? $c['user_id'] ?? $c['id']);
                $targetUsername = htmlspecialchars($c['username'] ?? '');
              ?>
              <label class="member-select-item">
                <input type="checkbox" name="members[]" value="<?= $targetUserId ?>">
                <input type="hidden" name="member_usernames[<?= $targetUserId ?>]" value="<?= $targetUsername ?>">
                <?= Helpers::renderAvatar($c['avatar_path'] ?? null, $c['username'] ?? 'U', 'avatar-xs') ?>
                <span class="member-select-name"><?= $targetUsername ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="extraUsernames">Или добавьте по @username (через запятую)</label>
        <input
          type="text"
          id="extraUsernames"
          name="extra_usernames"
          placeholder="например: chel, testik"
          autocomplete="off"
        >
      </div>

      <button type="submit" class="btn-submit mt-12">
        Создать группу
      </button>
    </form>
  </div>
</div>
