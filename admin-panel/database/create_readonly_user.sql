-- ШАГ 1. Создать read-only пользователя БД для админ-панели.
-- Выполнить под root: mysql -u root -p < database/create_readonly_user.sql
--
-- ВАЖНО: замените 'CHANGE_ME_STRONG_PASSWORD' на реальный пароль
-- и сохраните его в .env админ-панели как DB_PASSWORD.

CREATE USER IF NOT EXISTS 'bridge_admin_ro'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_PASSWORD';

-- Только чтение основных таблиц приложения
GRANT SELECT ON insales_bridge.insales_shops TO 'bridge_admin_ro'@'localhost';
GRANT SELECT ON insales_bridge.dellin_orders TO 'bridge_admin_ro'@'localhost';

-- Полные права на собственные таблицы админки (создаются через database/schema.sql)
GRANT SELECT, INSERT, UPDATE, DELETE ON insales_bridge.admin_users TO 'bridge_admin_ro'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON insales_bridge.admin_alerts TO 'bridge_admin_ro'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON insales_bridge.admin_login_attempts TO 'bridge_admin_ro'@'localhost';

FLUSH PRIVILEGES;

-- ШАГ 2. После применения схемы (schema.sql), создать первого админа.
-- Хэш пароля сгенерировать через: php -r "echo password_hash('ВАШ_ПАРОЛЬ', PASSWORD_BCRYPT);"
-- Затем:
-- INSERT INTO admin_users (email, password_hash) VALUES ('you@example.com', '$2y$...вставить хэш...');
