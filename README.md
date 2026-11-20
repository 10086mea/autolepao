# AutoLepao

一个可以自己部署的步道乐跑工具。扫码登录账号，在地图上画路线，检查跑区规则后预约任务。任务会在约定时间开始，按路线生成轨迹、上传，并在时间线结束后提交到乐跑。

支持微信和 QQ，能保存多个账号、查询本学期次数、复用画好的路线，也可以开启每日自动预约。学校、学期、跑区和打卡点从账号接口读取。

独立版从原来的 Flarum 插件中抽出登录和提交代码，使用 PHP 8.2+、SQLite 和 Leaflet。原插件保存在仓库的 `flarum` 分支。协议迁移参考 `flarum` 的 `bd774a5`，登录与公共参数采用后续修复版 `0c11073` 的实现。

## 版本

`main` 为当前独立版，`flarum` 保留插件版，`legacy-local` 保留迁移前的本地系统。历史开发分支以清理后的代码快照保留，已删除运行样本中的会话信息。旧提交号不再用于检出这些快照。

## 启动

### Docker

复制配置文件，修改访问密码，再启动。

```sh
cp .env.example .env
# 编辑 .env 中的 LEPAO_PASSWORD
docker compose up -d --build
```

打开 `http://127.0.0.1:8900`。Compose 默认只在本机开放端口。数据保存在 `lepao-data` 卷中。

### 直接运行 PHP

需要 PHP 8.2 或更高版本，以及 OpenSSL、PDO SQLite 扩展。推荐启用 cURL。依赖版本保存在 `composer.lock`。

```sh
composer install --no-dev
php bin/console.php serve
```

`serve` 一起启动网页和调度器，默认地址为 `http://127.0.0.1:8900`。关闭这个命令会停止两者。需要常驻运行时，可以交给 Docker 或自己的进程管理器。

Windows 可以使用同样的命令。在 `php.ini` 中启用 `extension=pdo_sqlite`。如果仅通过 `php -d` 加载扩展，需要用 `LEPAO_PHP_OPTIONS` 把参数传给子进程。

```powershell
$env:LEPAO_PHP_OPTIONS='["-d","extension=pdo_sqlite"]'
php -d extension=pdo_sqlite bin/console.php serve
```

单独管理进程时使用下面两个命令。网页的文档根目录必须是 `public`。

```sh
php -S 127.0.0.1:8900 -t public public/router.php
php bin/console.php worker
```

## 使用

1. 点“扫码登录”，选择与乐跑 App 一致的微信或 QQ 登录方式，在手机上确认授权。
2. 点账号的“刷新资料”，读取学校、当前学期、已完成次数和跑区。
3. 选择跑区。地图会显示候选打卡点和打卡半径。点击地图添加路线点，拖动圆点调整位置。
4. 设置里程、配速和路线走法。开放线路会沿原线往返，闭合线路会连接首尾，再按圈数延长到目标里程。
5. 点“预览并检查”。检查通过后，可以单次预约，也可以开启每日自动预约。

指定时间按北京时间解释。随机时间从未来可用时段里选择，计算时会扣除全程用时和五分钟提交余量。预约时检查一次，到开始前再检查一次。如果随机预约遇到规则变化或有效时段不足，会重新安排。指定时间不合适会直接提示。

每日预约按所选路线和配速，每天安排一次，直到本学期目标完成。目标优先从乐跑读取，也可以自行填写。安排前会核对今天的远端记录和本地提交状态。账号失效时暂停，重新扫码后自动继续。手动暂停需要自行重新开启。换学期后需要重新确认目标和路线。

## 扫码授权配置

QQ 登录沿用移动应用授权流程，应用 ID、包名和签名证书摘要写在 `src/Core/Qq.php` 中。

微信扫码需要有效的移动应用授权配置。在网页“设置”中填写应用 ID 和 Secret，或者设置 `LEPAO_WECHAT_APP_ID`、`LEPAO_WECHAT_SECRET`。环境变量优先。默认 ID 来自所分析的乐跑版本，仓库没有附带对应 Secret，单独填写默认 ID 无法完成微信授权。

授权流程涉及三方。微信或 QQ 返回授权信息后，程序再调用乐跑的 `Login/quickLoginV339`，拿到乐跑自己的会话。昵称查询失败不会丢弃已经成功的乐跑登录。同一乐跑 ID 再次扫码会更新原账号。

## 数据与部署

本应用按一个私人工作区设计，访问密码由部署者管理。工作区内可以看到其中所有账号和任务。对外提供服务时，每个使用者应拥有自己的实例或独立的访问隔离。

| 环境变量 | 用途 | 默认值 |
| --- | --- | --- |
| `LEPAO_DATA_DIR` | 数据目录 | 项目下的 `data` |
| `LEPAO_URL` | 网页访问地址和允许访问的主机 | `http://127.0.0.1:8900` |
| `LEPAO_LISTEN` | Web 进程监听地址 | 从 `LEPAO_URL` 读取，Docker 使用 `0.0.0.0:8900` |
| `LEPAO_PASSWORD` | 网页访问密码 | 本机运行可留空，远程访问必须设置 |
| `LEPAO_WAKE_PORT` | 本机调度通知端口 | `18901` |
| `LEPAO_WECHAT_APP_ID` | 微信应用 ID | 分析版本中的应用 ID |
| `LEPAO_WECHAT_SECRET` | 微信应用 Secret | 空 |
| `LEPAO_PHP_OPTIONS` | 子进程 PHP 参数，JSON 字符串数组 | `[]` |

`data/lepao.sqlite` 保存账号会话、设备信息、画线路线、任务和执行事件。授权配置在 `settings.json`，微信票据缓存和应用日志也在同一目录。凭据以本机可读取的形式保存，文件权限为当前系统用户可读写。备份时应整体保存数据目录，SQLite 在线备份建议使用 `.backup` 或停机复制，包含尚未合并的 WAL 数据。

地图使用 OpenStreetMap 瓦片，遵守浏览器的正常来源策略并显示署名。页面坐标是 WGS84，发给乐跑的坐标是 GCJ02，转换在 `public/app.js` 中完成。不要直接把两个坐标系的点混用。

反向代理时，请将请求交给 `public/router.php`，保留会话 Cookie，并正确配置 `LEPAO_URL` 和密码。提交中的 `ip` 使用创建任务请求的 `REMOTE_ADDR`，不会查询公网出口，也不会直接信任浏览器提供的 `X-Forwarded-For`。需要使用真实客户端地址时，应由代理和 Web 服务器配置可信代理边界。

## 代码从哪里看

| 文件 | 负责的内容 |
| --- | --- |
| `src/Core/Lepao.php` | 公共参数、版本号、登录、请求封装、学籍读取 |
| `src/Core/Crypto.php` | 两类签名、AES 封装、nonce 登录字段编码 |
| `src/Core/Wechat.php` | 微信票据、二维码、轮询、授权码交换、昵称 |
| `src/Core/Qq.php` | QQ 移动授权、签名、Cookie、二维码、回调解析 |
| `src/Core/Policy.php` | 跑区、学期、有效时段和候选打卡点 |
| `src/Core/TaskPlan.php` | 根据跑前返回的规则分配本次打卡点 |
| `src/Core/Rules.php` | 画线校验、距离计算、跑前检查 |
| `src/Core/Trajectory.php` | 沿画线采样、生成分段速度和时间线 |
| `src/Core/Runner.php` | 打卡日志、轨迹文件、OSS 上传、最终提交 |
| `src/Core/Schedule.php` | 学期、星期、时段交集和结束时间检查 |
| `src/App/Application.php` | 账号、二维码、路线、预约的应用操作 |
| `src/App/Worker.php` | 到期领取、开始、等待结束、提交和中断恢复 |
| `src/App/Hosting.php` | 每日预约、次数目标和当日成绩核对 |
| `src/Support` | HTTP、SQLite、私有状态、日志和调度通知 |
| `public/app.js` | 地图画线、坐标转换和网页交互 |

核心类使用普通数组作为输入输出。它们依赖可替换的 HTTP 和时钟，不依赖论坛用户、商城库存或论坛数据库。生成轨迹的入口是 `Trajectory::generate($config, $duration)`，画线数据在 `control_points` 中，每个点为 `a` 纬度、`o` 经度。

## 分析的软件版本

当前协议依据本地分析过的 Android 安装包，版本 `4.3.3`、版本号 `233`，包名 `com.lptiyu.tanke`。

| 项目 | 值 |
| --- | --- |
| APK SHA-256 | `72a93d55f0e7731263e6b4f844f6fae67628a9859234c7f02e185260893e2f73` |
| 签名证书 SHA-256 | `a6af18d3f047f3a210e9762f41b6f923f445151625fb5533c41748c7b02a6a24` |
| QQ 使用的证书 MD5 | `d6ab92f37e0bdbff74d08a64c19ea1d8` |
| 公共客户端标识 | `version=233`、`version_name=4.3.3`、`n=211` |

复核协议时，先记录安装包哈希与签名，再用 JADX 解包。混淆类名会随版本变化，下面这些名称只对应这一版安装包。

```sh
sha256sum budao-lepao-4.3.3.apk
apksigner verify --print-certs budao-lepao-4.3.3.apk
jadx -d work/decompiled budao-lepao-4.3.3.apk
rg 'quickLoginV339|beforeRunV260|stopRunV278' work/decompiled/sources
rg 'PARAMS_NEW_GAME|NEW_GAME|nonce' work/decompiled/sources
```

`ea/h.java` 构造公共参数，未登录时不会带历史账号的 Token。`ea/o.java` 集中了接口地址。`o9/c.java` 中有需要加密的接口列表和协议常量。`com/lptiyu/tanke/utils/a.java` 处理 `NEW_GAME`、`PARAMS_NEW_GAME` 等 AES 标签。`ea/k.java` 处理请求参数与提交签名。`com/lptiyu/tanke/utils/r2.java` 参与三方登录和 nonce 字段处理。QQ 的应用签名还需要对照随包 SDK 的 `AuthAgent` 和 `SystemUtils.getSignValidString`。

解包后应沿调用关系核对字段进入签名的顺序、是否被排除、何时加密，以及服务端响应是否还套了一层加密。只替换 `version_name` 会漏掉公共请求、登录字段和后续学籍请求。仓库保留的是重新实现的代码和版本定位信息，运行时不需要 APK、ADB 或 root 手机。

## 乐跑接口流程

接口基址为 `https://api2.lptiyu.com/v3/api.php/`，请求使用表单提交。

| 接口 | 用途 | 请求封装 |
| --- | --- | --- |
| `Login/quickLoginV339` | 用微信或 QQ 授权换取乐跑会话 | 新签名、AES 请求体、nonce 字段编码 |
| `Student/InfoV14` | 读取 UID、学校和学号 | 普通签名 |
| `Run2/beforeRunV260` | 学期统计、跑区、有效时段、打卡点 | 新签名、AES 请求体 |
| `Run/GetZonePoints` | 读取其他跑区的候选点 | 普通签名 |
| `Run/setRunZone` | 切换并确认跑区 | 普通签名 |
| `Run/getTimestampV278` | 取得本次提交使用的服务器字符串 | 普通签名 |
| `report/getOssSts` | 获取临时上传凭据 | 新签名、AES 请求体 |
| OSS `PUT` | 上传 AES 编码的轨迹文件 | 临时凭据、Content-MD5、HMAC-SHA1 |
| `Run/stopRunV278` | 提交距离、用时、步数、打卡日志与文件键 | 新签名、AES 请求体 |
| `Run2/allRecordList` | 每日预约前核对已有成绩 | 普通签名 |

普通签名按参数名排序后拼接并追加协议常量。新签名使用另一组常量，并排除部分字段。登录时 `openid` 和 `accesstoken` 按 nonce 选择一组 AES 参数，之后再对完整请求做签名和封装。准确实现见 `Crypto::sign()` 和 `Lepao::call()`。

账号自己的 UID、Token、学校、学号和设备信息参与公共参数。设备在首次扫码时生成并保存，重新扫码和后续请求继续使用这份配置。型号池来自 `resources/android-devices.txt`，型号代码和 Android 版本成对选择，来源写在文件注释中。

轨迹文件包含坐标、速度、公里标记等字段，打卡日志单独放入 `log_data`。提交中的 `record_str` 使用学校、UID、服务器字符串和协议常量计算。生成几何轨迹本身在本机完成，跑前配置、服务器字符串、上传授权、文件上传和最后提交需要联网。

## 踩过的坑

- **扫码成功后学籍接口仍报错。** 曾经只升级了登录请求，公共请求仍使用 `4.1.6/216`。现在版本在所有附加参数合并后统一写入，避免端点参数覆盖。
- **账号换设备后被误判失效。** 登录设备和后续请求设备必须一致。现在设备按账号保存，工作中的任务还会核对登录会话是否变化。
- **微信资料和乐跑会话是两套状态。** 微信昵称接口的令牌失效，不能单独证明乐跑会话已失效。账号状态从乐跑自己的接口判断。
- **候选打卡点不等于本次实际打卡点。** 分配逻辑读取点类型、在线状态、距离阈值和当时的数量规则。轨迹检查和最终日志只使用这次分配的点。
- **不同学校的打卡规则不同。** 当前移植的分配器支持 `set_point_type=1`、`repeat_num=0` 的在线打卡模式。遇到现场扫码、未知点类型或不完整规则时，会显示具体原因。新增规则应先对照对应版本客户端，补实现与测试。
- **地图偏移会导致打卡点落在轨迹外。** OSM 底图与乐跑坐标必须转换。画线保存和提交统一使用 GCJ02，显示时转换回底图坐标。
- **提交请求超时不能直接重发。** 乐跑可能已经收到请求。本地将这类任务标为“需要核对”，每日预约也会检查远端记录。进程在提交阶段退出时同样处理。
- **任务已结束不一定有有效成绩。** 页面保留乐跑返回的 `record_status`，`1` 才显示有效。未返回最终判定时显示等待判定。
- **每秒扫描历史任务会拖慢数据库。** 调度器按最近的开始或结束时间阻塞等待，网页在数据库提交成功后发本机 UDP 通知。另有十五分钟一次的补偿检查，启动时恢复已有预约和运行中的时间线。

客户端还有传感器、模拟器、定位与设备相关检查。当前程序根据移植的提交格式构造请求，没有采集手机的真实传感器数据，也没有实现所有学校的现场核验流程。支持扫码和提交协议，并不能据此保证远端一定判定有效。

## 本机 HTTP 接口

网页和 API 共用 Cookie 会话。先调用 `GET /api/state` 获取 CSRF 值，所有写请求通过 `X-CSRF-Token` 传回。登录密码在 `/login` 表单提交。

| 方法和路径 | 内容 |
| --- | --- |
| `GET /api/state` | 账号、任务、路线、每日预约和公开配置 |
| `POST /api/bindings` | 创建微信或 QQ 二维码，字段 `provider` |
| `GET /api/bindings/{id}` | 查询扫码状态 |
| `POST /api/accounts/{id}/check` | 检测账号并刷新学期信息 |
| `POST /api/accounts/{id}/zone` | 读取 `game_id` 对应的跑前规则 |
| `DELETE /api/accounts/{id}` | 删除账号及其本地历史，执行中会拒绝 |
| `POST /api/preview` | 检查路线并返回采样结果 |
| `POST /api/tasks` | 创建预约 |
| `POST /api/tasks/{id}/cancel` | 取消尚未开始的预约 |
| `POST /api/hosting` | 开启每日预约，额外字段 `target` 可省略 |
| `POST /api/hosting/{account_id}/stop` | 暂停每日预约 |
| `POST /api/routes` | 保存画线，字段 `name`、`config` |
| `POST /api/settings` | 保存微信授权配置 |

预览和预约接收 `account_id`、`config`、`mode`，指定时间另带 Unix 秒 `requested_at`。`config` 包含 `game_id`、`distance_km`、`pace_s_per_km`、`closed` 和 `control_points`。学校和学期由服务器端读取。

## 验证与日志

```sh
composer test
php bin/console.php status
```

测试使用固定的 HTTP 响应，覆盖账号资料、签名、版本锁定、地图路线、打卡点、时间窗口、轨迹上传、最后提交和超时恢复。它们不会登录真实账号或创建远端成绩。真实环境的微信和 QQ 授权、特定学校规则、最终成绩需要使用者扫码后再验证。

任务事件保存开始、参数摘要、上传、提交和错误。等待期间不会每隔几秒追加一条计时。应用日志记录阶段、异常类型和经过筛选的错误码，不写 Token、扫码 Cookie、完整回调或远端原始响应。

## 许可与来源

项目代码按 MIT 许可发布。Leaflet 使用 BSD-2-Clause，许可保存在 `public/vendor/LICENSE`。PHP 依赖的许可见各自包目录。设备型号参考 MobileModels，具体文件和版本来源见设备表注释。地图数据署名保留在界面中。
