# Fediverse for Typecho

Fediverse 是面向 Typecho 1.3.0 的多作者 ActivityPub 插件。每位 Typecho 作者拥有独立联邦账号、独立 RSA 密钥、收件箱、发件箱和关注者集合。

当前版本：`0.2.1`（MVP）

## 功能

- WebFinger 账号发现：`@author@example.com`
- ActivityPub `Person` Actor，每位作者独立账号和密钥
- 文章发布、更新、删除的 `Create`、`Update`、`Delete` 投递
- `Follow`、`Accept` 和 `Undo Follow`
- Mastodon、Misskey 等远端回复写入 Typecho 评论审核队列
- HTTP Signatures、Digest、Date 验证和活动去重
- 后台投递队列、指数退避和最多 8 次重试
- “管理 → 联邦宇宙”运行状态页
- 作者身份、关注者、投递队列和入站活动查看
- 失败任务保留、手动重试、删除和作者身份批量生成
- 入站活动日志按保留天数自动清理
- NodeInfo 2.1
- MySQL、MariaDB、PostgreSQL 和 SQLite 建表支持

## 要求

- Typecho 1.3.0
- PHP 8.2 或更高版本
- PHP OpenSSL 和 cURL 扩展
- HTTPS
- URL 重写必须把 `/.well-known/*` 与 `/fediverse/*` 交给 Typecho
- 能定时访问队列处理 URL 的 Cron 服务

## 安装

1. 将整个 `Fediverse` 目录放入 Typecho 的 `usr/plugins/`。
2. 在 Typecho 后台启用 **Fediverse**。
3. 打开插件设置并保存自动生成的 Cron 令牌。
4. 每位作者在个人设置中的插件配置里确认联邦用户名和简介。
5. 配置 Web 服务器重写与 Cron。

从 `0.1.0` 升级时直接覆盖插件目录，然后先停用再重新启用一次插件，以注册后台管理面板和管理 Action。原有密钥、关注者和队列数据不会被删除。

从 `0.2.0` 升级到 `0.2.1` 时必须完整覆盖插件目录，确认新增的 `bootstrap.php` 已上传。若服务器启用了 PHP OPcache，覆盖后重载 PHP-FPM 或在 Typecho 中停用再启用插件一次。

停用插件不会删除数据表和私钥，以免重新启用后联邦身份发生变化。需要永久卸载时，请先备份，再手工删除名称以 `fediverse_` 开头的数据表。

## Web 服务器

Typecho 已启用全站伪静态时，现有的“不存在文件交给 `index.php`”规则通常已经足够。必须确认以下地址不会被服务器直接返回 404：

```text
https://example.com/.well-known/webfinger?resource=acct:author@example.com
https://example.com/.well-known/nodeinfo
https://example.com/fediverse/author/author
```

Nginx 的典型规则：

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}
```

Apache 需要启用 `mod_rewrite`，并使用 Typecho 后台生成的伪静态规则。

如果 Typecho 安装在子目录，WebFinger 仍必须位于域名根目录。此时需要在反向代理层把 `/.well-known/webfinger` 和 `/.well-known/nodeinfo` 转发到 Typecho 子目录中的同名路由。

## Cron

保存插件设置后，每分钟请求一次：

```text
https://example.com/fediverse/cron/你的Cron令牌
```

例如：

```cron
* * * * * curl -fsS --max-time 50 'https://example.com/fediverse/cron/替换为令牌' >/dev/null
```

令牌会出现在服务器访问日志中，应使用随机长令牌并限制日志访问。也可以在反向代理层限制该路径的来源 IP。

Cron 每次会处理插件设置中指定数量的投递任务，并清理超过“入站活动日志保留天数”的记录。连续失败 8 次的任务会停止自动重试并保留在后台，不会静默删除。

## 后台管理

启用后进入 Typecho 的“管理 → 联邦宇宙”，可以查看：

- 每位作者的联邦地址、Actor 状态和密钥生成时间
- 当前关注者及其共享收件箱
- 等待投递和已停止重试的任务
- 最近接收的 Follow、Create、Like、Announce 等活动

后台支持立即处理当前批次、重新投递失败任务、删除无效任务、移除关注者、批量生成作者身份和清理过期活动。所有管理操作均要求管理员权限并通过 Typecho 安全令牌校验。

## 联邦账号规则

- 默认用户名来自 Typecho 登录名。
- 非法字符会被移除；结果为空时使用 `user<uid>`。
- 作者可以在个人插件设置中指定只含字母、数字、下划线和连字符的用户名。
- 作者首次被发现或首次发布时生成 2048 位 RSA 密钥。
- 修改已联邦化的用户名会改变 Actor URL，不建议修改。

## 数据与审核

远端 `Note` 回复仅在 `inReplyTo` 指向本站已发布文章、文章允许评论且收件人匹配作者时接收。内容转成纯文本，并以 `waiting` 状态写入 Typecho 评论表，管理员审核后才公开。

插件仅接受 HTTPS 远端 URL，拒绝解析到私有、保留或回环 IP 的地址，以降低 SSRF 风险。所有入站活动必须带有效的 `Digest`、`Date` 和 HTTP Signature。

## 当前限制

- 暂不联邦发送 Typecho 本地评论。
- 暂不处理远端媒体附件、投票、私信和内容警告。
- 删除投递依赖文章发布后已写入插件跟踪表；安装插件前的旧文章需重新保存发布，才会主动推送。
- 不同 Fediverse 实现对 HTTP Signatures 的细节存在差异，上线前应分别使用 Mastodon 与 Misskey 测试。

## 上线检查

1. 用浏览器请求 WebFinger，确认返回 `application/jrd+json`。
2. 从 Mastodon 搜索完整账号 `@author@example.com`。
3. 关注账号，确认 Typecho 数据表出现关注者并成功回送 `Accept`。
4. 发布测试文章，确认一分钟内在远端时间线出现。
5. 从远端回复，确认 Typecho 后台出现待审核评论。
6. 在“管理 → 联邦宇宙 → 投递队列”确认没有持续失败任务。

## 许可证

GPL-2.0-or-later，与 Typecho 插件生态保持一致。
