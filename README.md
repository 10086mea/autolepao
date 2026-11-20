# AutoLepao

给自己账号使用的步道乐跑命令行工具。扫码登录、导入路线，然后按学校的有效时段自动提交。

## Windows

1. 到 [Releases](https://github.com/10086mea/autolepao/releases/latest) 下载 `autolepao-windows-x64.zip` 并解压。
2. 双击 `开始.cmd`，按提示扫码登录，选择路线和跑区。
3. 保持窗口开启，程序会等待开始时间，完成后显示乐跑返回的结果。

运行包已经包含所需环境。首次设置后会记住账号、设备和路线，下次直接启动。

在终端中也可以使用下面的命令。

```bat
lepao.cmd
lepao.cmd --daily
lepao.cmd status
```

`--daily` 每天自动安排一次，达到学期目标后结束。运行期间需要保持电脑开机、联网。

## 路线

导入 GPX 或 GeoJSON 文件，坐标使用 WGS84。程序会转成乐跑使用的 GCJ02 坐标。

闭合路线按圈数延长，开放路线沿原线往返。里程、配速、有效时间和打卡点会在提交前检查。

需要更换路线或参数时，运行 `lepao.cmd setup`。指定一次开始时间可以使用 `lepao.cmd --at "2026-12-01 14:00"`，时间按北京时间解释。

## 登录与数据

默认使用 QQ 扫码。微信扫码还需要有效的移动应用 ID 和 Secret，选择微信后会提示填写。仓库没有附带对应 Secret。

个人数据保存在程序旁的 `data` 目录。重新扫码用 `lepao.cmd login`，查询次数和上次结果用 `lepao.cmd status`。

程序在提交过程中断或超时，会保留待确认状态。重新运行时会恢复时间线，或提示先核对远端成绩，避免重复提交。

## 从源码运行

已有 PHP 8.2+、cURL、OpenSSL 和 SimpleXML 环境时，直接运行 `php lepao.php`。开发验证运行 `php tests/run.php`，打包运行 `php -d phar.readonly=0 tools/build.php`。

仓库只有一个 `main` 分支。[协议与研究记录](docs/protocol.md)包含 APK 信息、签名、接口流程和已知限制。

项目代码采用 MIT 许可，Windows 包中的 PHP 和 Microsoft 运行库使用各自许可。
