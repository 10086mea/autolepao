<!doctype html>
<html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 · AutoLepao</title><link rel="stylesheet" href="/app.css">
<body class="login"><form action="/login" method="post" class="panel login-form">
<span class="wordmark">AutoLepao</span><h1>进入你的工作区</h1>
<label>访问密码<input name="password" type="password" required autofocus autocomplete="current-password"></label>
<input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="primary">登录</button>
</form></body></html>
