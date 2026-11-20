<?php
if ($_SERVER['REQUEST_URI'] === '/set') setcookie('fixture', 'cookie-value', ['path' => '/allowed', 'httponly' => true]);
if ($_SERVER['REQUEST_URI'] === '/redirect') { header('Location: /target', true, 302); exit; }
header('Content-Type: application/json');
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'form' => $_POST, 'cookies' => $_COOKIE, 'agent' => $_SERVER['HTTP_USER_AGENT'] ?? '']);
