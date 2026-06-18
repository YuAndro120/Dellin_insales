<?php

declare(strict_types=1);

namespace AdminPanel\Pages;

use AdminPanel\Auth;
use AdminPanel\Config;
use AdminPanel\Db;

final class LoginPage
{
    public static function handle(Config $config, string $method): void
    {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }

        $error = null;

        if ($method === 'POST') {
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $pdo = Db::pdo($config);

            if ($email === '' || $password === '') {
                $error = 'Заполните email и пароль.';
            } elseif (Auth::attempt($config, $pdo, $email, $password)) {
                header('Location: /');
                exit;
            } else {
                $error = 'Неверный email, пароль, или превышен лимит попыток входа.';
            }
        }

        self::render($error);
    }

    private static function render(?string $error): void
    {
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        \AdminPanel\Layout::head('Вход');
        echo '<div class="login-wrap"><div class="login-card">';
        echo '<div class="login-title">Bridge Admin</div>';
        echo '<div class="login-sub">Доступ только для администратора</div>';
        if ($error !== null) {
            echo '<div class="alert-banner-err">' . $h($error) . '</div>';
        }
        echo '<form method="post" action="/login">';
        echo '<div class="field"><label>Email</label><input type="email" name="email" required autofocus></div>';
        echo '<div class="field"><label>Пароль</label><input type="password" name="password" required></div>';
        echo '<button type="submit" class="btn" style="width:100%">Войти</button>';
        echo '</form></div></div>';
        echo '</body></html>';
    }
}
