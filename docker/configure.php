<?php declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$path = dirname(__DIR__) . '/.env';
$text = (string)file_get_contents(file_exists($path) ? $path : dirname(__DIR__) . '/.env.example');
$text = str_replace("\r\n", "\n", $text);
foreach (['ECW_DB_PASSWORD', 'ECW_DB_ROOT_PASSWORD', 'ECW_SETUP_TOKEN'] as $name) {
    if (!preg_match('/^' . $name . '=(.*)$/m', $text, $match)) {
        $text .= "\n" . $name . '=' . bin2hex(random_bytes(24)) . "\n";
    } elseif (trim($match[1], " \t\"'") === '') {
        $text = preg_replace('/^' . $name . '=.*$/m', $name . '=' . bin2hex(random_bytes(24)), $text);
    }
}
if (file_put_contents($path, $text) === false) throw new RuntimeException('Cannot write .env in this folder.');
chmod($path, 0600);
preg_match('/^ECW_SETUP_TOKEN=(.*)$/m', $text, $token);
echo 'First-admin setup code: ' . trim($token[1], " \t\"'") . PHP_EOL;
preg_match('/^ECW_HTTP_PORT=(.*)$/m', $text, $port);
echo 'After startup, open http://localhost:' . ($port[1] ?? '8080') . '/other_things/web/setup.php' . PHP_EOL;
