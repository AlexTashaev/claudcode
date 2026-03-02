# Руководство по настройке Telegram Login для KAB Academy

Интеграция входа через Telegram для kabacademy.com (WordPress) и edu.kabacademy.com (Moodle).

## Предварительные требования

- WordPress-сайт: `kabacademy.com`
- Moodle-сайт: `edu.kabacademy.com`
- Edwiser Bridge Pro + SSO установлены и работают
- Telegram-бот создан (токен доступен)

---

## Шаг 1: Настройка Telegram-бота

1. Откройте Telegram, найдите `@BotFather`
2. Отправьте `/setdomain`
3. Выберите вашего бота
4. Введите: `kabacademy.com`

> **Важно:** домен должен совпадать точно. Если используется `www.kabacademy.com`, настройте редирект на версию без `www` (или добавьте оба домена).

---

## Шаг 2: Установка плагина WP Telegram Login & Register

1. В админке WordPress (`kabacademy.com/wp-admin/`) → **Плагины → Добавить**
2. Поиск: `WP Telegram Login`
3. Установите и активируйте **WP Telegram Login & Register** от WP Socio
4. Перейдите в **Настройки → WP Telegram Login**
5. Настройте:
   - **Bot Token:** вставьте токен бота
   - **Bot Username:** имя бота (без @)
   - **Button Style:** Large
   - **Show User Photo:** по желанию
   - **Corner Radius:** 15
   - **Show if user is:** Logged Out
   - **Disable Sign up:** ВКЛ (наш mu-plugin тоже это гарантирует программно)
   - **Redirect After Login:** оставьте Default (mu-plugin управляет редиректом)
6. Сохраните

---

## Шаг 3: Установка WordPress mu-plugin

1. Скопируйте `mu-plugin/kab-telegram-bridge.php` в `wp-content/mu-plugins/`
2. Создайте директорию `wp-content/mu-plugins/kab-telegram-bridge/`
3. Скопируйте все файлы из `mu-plugin/kab-telegram-bridge/` в эту директорию

Структура должна быть:

```
wp-content/mu-plugins/
├── kab-telegram-bridge.php
└── kab-telegram-bridge/
    ├── class-kab-telegram-guard.php
    ├── class-kab-moodle-linker.php
    ├── class-kab-telegram-widget.php
    └── class-kab-login-customizer.php
```

4. Проверьте в админке: **Плагины → Must-Use** — должен отображаться «KAB Academy Telegram Bridge»

---

## Шаг 4: Установка Moodle auth plugin

1. Скопируйте директорию `moodle-auth-plugin/telegram_wp/` в `moodle/auth/telegram_wp/`

Структура:

```
moodle/auth/telegram_wp/
├── auth.php
├── version.php
├── settings.php
├── pix/
│   └── telegram.svg
└── lang/
    ├── en/auth_telegram_wp.php
    └── ru/auth_telegram_wp.php
```

2. В Moodle: **Site administration → Notifications** — запустится установка плагина
3. Перейдите в **Site administration → Plugins → Authentication → Manage authentication**
4. Включите **Telegram via WordPress** (значок глаза)
5. Нажмите **Settings** рядом с плагином:
   - **WordPress login URL:** `https://kabacademy.com/wp-login.php`
   - **Button text:** `Войти через Telegram` (или оставьте по умолчанию)
6. Сохраните

---

## Шаг 5: Проверка Edwiser Bridge SSO

1. WordPress: **Edwiser Bridge → Settings → General** — убедитесь что **Secret Key** задан в SSO Settings
2. Moodle: **Site administration → Plugins → Authentication → Edwiser Bridge SSO**:
   - Тот же Secret Key
   - WordPress URL: `https://kabacademy.com`
3. Проверьте что SSO работает обычным способом (вход в WP → переход в Moodle)

---

## Шаг 6: Привязка Telegram-аккаунтов пользователей

Поскольку авто-регистрация отключена, существующие пользователи должны привязать Telegram.

### Вариант A: Самообслуживание

1. Пользователь входит в `kabacademy.com` обычным способом (логин/пароль)
2. Переходит в **Профиль** (`/wp-admin/profile.php`)
3. В секции «Telegram Account» нажимает кнопку привязки
4. Подтверждает в Telegram
5. Теперь может входить через Telegram

### Вариант B: Массовая привязка (администратор)

Если Telegram ID пользователей известны, администратор может привязать через WP-CLI:

```bash
wp user meta update <user_id> wptelegram_user_id <telegram_user_id>
```

---

## Проверка работоспособности

### Сценарий A: Вход через WordPress

1. Откройте `kabacademy.com/wp-login.php`
2. Нажмите кнопку «Login with Telegram»
3. Подтвердите в Telegram
4. Должны быть перенаправлены на страницу аккаунта
5. Перейдите на `edu.kabacademy.com` — SSO должен автоматически залогинить

### Сценарий B: Прямой вход через Moodle

1. Откройте прямую ссылку на курс: `edu.kabacademy.com/course/view.php?id=123`
2. На странице логина Moodle должна быть кнопка «Войти через Telegram»
3. Нажмите — перенаправление на WordPress
4. Авторизуйтесь через Telegram
5. Должны вернуться обратно на страницу курса в Moodle

### Тесты на ошибки

- **Непривязанный Telegram** — должно показать сообщение «привяжите аккаунт сначала»
- **Новый пользователь** — должно показать «signup disabled»
- **Отвязка Telegram** — кнопка «Disconnect» в профиле должна работать

---

## Устранение проблем

| Проблема | Решение |
|----------|---------|
| Кнопка Telegram не появляется на WP login | Проверьте что WP Telegram Login активирован и Bot Token задан |
| «Sign up via Telegram is disabled» | Это ожидаемое поведение для непривязанных аккаунтов |
| SSO не работает после Telegram-логина | Проверьте Secret Key в обоих системах (WP и Moodle) |
| Кнопка не появляется на Moodle login | Убедитесь что auth_telegram_wp включён в Manage authentication |
| После входа не перенаправляет в Moodle | Проверьте параметр `moodle_redirect_to` и что `edu.kabacademy.com` разрешён |
| Ошибка «Edwiser Bridge not available» | Убедитесь что Edwiser Bridge Pro активирован |
