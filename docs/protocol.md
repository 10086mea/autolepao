# 协议与研究记录

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
| `Run2/allRecordList` | 每日运行前核对已有成绩 | 普通签名 |

普通签名按参数名排序后拼接并追加协议常量。新签名使用另一组常量，并排除部分字段。登录时 `openid` 和 `accesstoken` 按 nonce 选择一组 AES 参数，之后再对完整请求做签名和封装。准确实现见 `Crypto::sign()` 和 `Lepao::call()`。

账号自己的 UID、Token、学校、学号和设备信息参与公共参数。设备在首次扫码时生成并保存，重新扫码和后续请求继续使用这份配置。型号池来自 `resources/android-devices.txt`，型号代码和 Android 版本成对选择，来源写在文件注释中。

轨迹文件包含坐标、速度、公里标记等字段，打卡日志单独放入 `log_data`。提交中的 `record_str` 使用学校、UID、服务器字符串和协议常量计算。生成几何轨迹本身在本机完成，跑前配置、服务器字符串、上传授权、文件上传和最后提交需要联网。

## 踩过的坑

- **扫码成功后学籍接口仍报错。** 曾经只升级了登录请求，公共请求仍使用 `4.1.6/216`。现在版本在所有附加参数合并后统一写入，避免端点参数覆盖。
- **账号换设备后被误判失效。** 登录设备和后续请求设备必须一致。现在设备按账号保存，工作中的任务还会核对登录会话是否变化。
- **微信资料和乐跑会话是两套状态。** 微信昵称接口的令牌失效，不能单独证明乐跑会话已失效。账号状态从乐跑自己的接口判断。
- **候选打卡点不等于本次实际打卡点。** 分配逻辑读取点类型、在线状态、距离阈值和当时的数量规则。轨迹检查和最终日志只使用这次分配的点。
- **不同学校的打卡规则不同。** 当前移植的分配器支持 `set_point_type=1`、`repeat_num=0` 的在线打卡模式。遇到现场扫码、未知点类型或不完整规则时，会显示具体原因。新增规则应先对照对应版本客户端，补实现与测试。
- **地图偏移会导致打卡点落在轨迹外。** GPX、GeoJSON 中的 WGS84 坐标需要转换成乐跑使用的 GCJ02。转换实现见 `src/Route.php`。
- **提交请求超时不能直接重发。** 乐跑可能已经收到请求。本地将这类任务标为“需要核对”，每日运行也会检查远端记录。进程在提交阶段退出时同样处理。
- **任务已结束不一定有有效成绩。** 命令行保留乐跑返回的 `record_status`，`1` 才显示有效。未返回最终判定时显示等待判定。

客户端还有传感器、模拟器、定位与设备相关检查。当前程序根据移植的提交格式构造请求，没有采集手机的真实传感器数据，也没有实现所有学校的现场核验流程。支持扫码和提交协议，并不能据此保证远端一定判定有效。
