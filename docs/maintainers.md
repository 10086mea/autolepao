# 发布程序

日常使用下载便携包即可。本页供维护代码和发布版本时使用。

## 开发

使用 Python 3.11 或更高版本。Linux 还需要系统的 Tk 支持。

```sh
python -m pip install -r requirements-build.txt
python -m unittest discover -s tests -v
python lepao.py
```

首次迁移时，把已部署项目的微信应用配置原样复制成根目录的 `_build_config.json`。这个文件已被 Git 忽略；源代码运行和打包使用同一份配置。程序也能直接读取旧项目的 `.local/wechat.json`，不再向使用者询问 Secret。

配置由发布者提供，不属于最终用户的安装步骤。不要把账号的 Token、扫码结果、`data` 目录或服务端私钥混进发布配置。便携包内的应用配置可被读取，应只使用获准随客户端分发的配置。

## Windows 打包

在 Windows 上执行：

```sh
python tools/build.py
```

输出为 `dist/python/autolepao-python-windows-x64.zip`，包含 Python、Tk、地图组件和应用配置。解压后直接运行 `lepao.exe`。包里有各依赖的许可文件，`SHA256SUMS` 用来核对压缩包。

打包脚本只收集应用源码、资源和明确指定的发布配置，不会复制本地的 `data` 目录。发布前在一个全新目录解压检查，分别验证微信二维码、地图保存、账号请求版本和中断恢复。测试只使用合成账号数据；完整登录需要真实用户扫码确认，测试不会代替用户登录或提交。

## 代码位置

| 文件 | 负责内容 |
| --- | --- |
| `lepao/login.py` | 微信和 QQ 二维码、轮询、授权交换 |
| `lepao/protocol.py` | 公共参数、AES、签名、登录和学籍接口 |
| `lepao/desktop.py` | 原生扫码窗口与地图画线 |
| `lepao/route.py` | 坐标转换、画线校验、轨迹和速度时间线 |
| `lepao/plan.py` | 学校规则、时段、学期和打卡点分配 |
| `lepao/run.py` | 上传、实时等待、提交、远端记录核对 |
| `lepao/storage.py` | 本地状态、原子写入和进程锁 |
| `lepao/cli.py` | 交互菜单、单次运行和每日托管 |

不需要 Web 服务、数据库、队列、论坛账号或调度服务。每日托管由当前进程等待到预约时间；退出程序会停止等待，已经开始的跑步保存在本地，可以恢复。
