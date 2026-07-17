# Fediverse for Typecho

Fediverse 是面向 Typecho 1.3.0 的多作者 ActivityPub 插件。每位 Typecho 作者拥有独立联邦账号、独立 RSA 密钥、收件箱、发件箱和关注者集合。

当前版本：`0.4.3`

## 功能

- WebFinger 账号发现：`@author@example.com`
- ActivityPub `Person` Actor，每位作者独立账号和密钥
- 文章发布、更新、删除的 `Create`、`Update`、`Delete` 投递
- `Follow`、`Accept` 和 `Undo Follow`
- 后台搜索并关注远端账号，处理 `Accept`、`Reject` 和取消关注
- 已关注账号的后台时间轴，以及点赞、转发、回复和对应撤销操作
- Mastodon、Misskey 等远端回复写入 Typecho 评论审核队列，并同步处理远端编辑与删除
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

从 `0.2.1` 升级到 `0.3.0` 无需迁移数据表，完整覆盖插件目录即可。文章详情互动区需要主题主动调用渲染函数，具体修改见“主题适配”。

从 `0.3.0` 升级到 `0.4.0` 后，打开一次“管理 → 联邦宇宙”，插件会自动创建关注、时间轴和本地回复数据表，并注册回复对象路由，无需停用插件。

从 `0.4.0` 升级到 `0.4.1` 无需迁移数据表。此版本增加远端 WebFinger 与 Actor 的安全 HTTPS 重定向兼容。

从 `0.4.1` 升级到 `0.4.2` 无需迁移数据表。联邦 ID 现在始终使用插件设置中的联邦域名，不再随 Cron 或后台请求使用的 `www`、裸域名而变化。升级前若已有远端关注者集合记录了错误的 `www` Actor URL，请在远端移除该关注关系，并在本站取消后重新关注一次。

从 `0.4.2` 升级到 `0.4.3` 无需迁移数据表。此版本会隔离主题和内容插件在读取文章时产生的意外输出，避免 BOM 或空白破坏 ActivityPub JSON。升级前未被远端识别的文章需要重新保存一次，并运行投递队列补发更新。

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

Cron 每次会处理插件设置中指定数量的投递任务，并清理超过“入站活动与时间轴保留天数”的记录。连续失败 8 次的任务会停止自动重试并保留在后台，不会静默删除。

## 后台管理

启用后进入 Typecho 的“管理 → 联邦宇宙”，可以查看：

- 每位作者的联邦地址、Actor 状态和密钥生成时间
- 当前关注者及其共享收件箱
- 主动关注的远端账号及等待、接受、拒绝状态
- 已关注账号投递的后台时间轴
- 等待投递和已停止重试的任务
- 最近接收的关注、回复、点赞、转发等活动及其处理状态

后台支持立即处理当前批次、重新投递失败任务、删除无效任务、移除关注者、批量生成作者身份和清理过期活动。联邦地址及端点可直接复制，操作完成后会保留当前视图。所有管理操作均要求管理员权限并通过 Typecho 安全令牌校验。

## 关注与时间轴

进入“管理 → 联邦宇宙 → 正在关注”，选择一个本地作者身份并输入完整账号，例如 `@user@example.com`。插件会通过 WebFinger 查找远端 Actor，并把签名 `Follow` 加入投递队列。远端返回 `Accept` 后状态变为“已关注”；返回 `Reject` 时显示“已拒绝”。

关注成功后，远端服务器会把该账号的新 `Note` 或 `Article` 主动投递到本站收件箱。插件验签并确认发送者处于关注列表后，将纯文本内容写入“时间轴”。同一远端账号被多个本地作者关注时，一次共享收件箱投递会分别写入各自的时间轴记录。

时间轴支持：

- 点赞和取消点赞，对应 `Like` 与 `Undo Like`。
- 转发和取消转发，对应 `Announce` 与 `Undo Announce`。
- 发送公开回复，对应包含 `inReplyTo` 的 `Create Note`。
- 接收已有时间轴内容的 `Update` 和 `Delete`。

所有出站互动先进入投递队列，由后台“立即处理队列”或 Cron 发送。时间轴只在管理员后台显示，不会自动公开远端内容。远端 HTML 会转换为纯文本，当前不下载或代理附件。

后台时间轴保存的是本站主动关注账号的新内容；文章详情互动区统计的是其他联邦账号对本站文章发来的点赞、转发和回复。两者数据来源不同，主题只需按下一节接入文章互动区，不应直接读取时间轴数据表。

## 主题适配

插件不会全局改写文章正文。主题需要在文章详情模板中调用渲染函数，才能显示联邦点赞、转发、已审核回复数量和最近互动者。当前站点的 Berry 主题包含以下两处修改；更新或更换主题后需要重新应用。

### 显示文章互动区

在主题的 `post.php` 中找到文章正文或标签列表结束的位置，将下面代码放在上一篇/下一篇导航或评论区之前。这里的 `$this` 必须是当前文章的 `Widget_Archive`：

```php
<?php if (class_exists('Fediverse_Plugin')): ?>
    <?php Fediverse_Plugin::renderPostInteractions($this); ?>
<?php endif; ?>
```

Berry 主题当前把它放在 `.tag-list` 结束后、`post-navigation` 之前。`class_exists` 检查确保插件停用后主题仍能正常渲染。渲染函数自带作用域为 `.fediverse-interactions` 的样式，并优先使用 Berry 的 CSS 变量；其他主题没有这些变量时会使用内置回退颜色。

如果主题需要完全自定义 HTML，可以只获取结构化数据：

```php
<?php
$interactions = class_exists('Fediverse_Plugin')
    ? Fediverse_Plugin::postInteractions((int)$this->cid)
    : array('likes' => 0, 'announces' => 0, 'replies' => 0, 'recent' => array());
?>
```

返回字段：

- `likes`：尚未撤销的远端点赞活动数量。
- `announces`：尚未撤销的远端转发活动数量。
- `replies`：已经在 Typecho 后台审核通过的联邦回复数量。
- `recent`：最多 12 条最近点赞或转发记录，每项包含 `type`、`actor` 和 `created`。

待审核、已忽略、已删除或已撤销的活动不会计入公开统计。远端回复本身仍由 Typecho 原生评论列表输出，互动区只显示数量，不会重复渲染评论正文。

### 标记联邦回复

如需在原生评论列表中区分远端回复，在主题 `comments.php` 的单条评论回调中，紧跟评论作者名称加入：

```php
<?php if ((string)$comments->agent === 'ActivityPub'): ?>
    <span class="fediverse-comment-source">联邦宇宙</span>
<?php endif; ?>
```

Berry 主题将这段代码放在 `threadedComments($comments, $options)` 的作者名称之后。其他主题若使用不同的评论回调函数，应放到对应的评论作者区域。调用 `renderPostInteractions()` 时已经包含 `.fediverse-comment-source` 样式；若只使用 `postInteractions()` 自定义互动区，则主题也需要自行定义该标记的样式。

## 联邦账号规则

- 默认用户名来自 Typecho 登录名。
- 非法字符会被移除；结果为空时使用 `user<uid>`。
- 作者可以在个人插件设置中指定只含字母、数字、下划线和连字符的用户名。
- 作者首次被发现或首次发布时生成 2048 位 RSA 密钥。
- 修改已联邦化的用户名会改变 Actor URL，不建议修改。

## 数据与审核

远端 `Note` 回复仅在 `inReplyTo` 指向本站已发布文章、文章允许评论且收件人匹配作者时接收。内容转成纯文本，并以 `waiting` 状态写入 Typecho 评论表，管理员审核后才公开。

插件仅接受 HTTPS 远端 URL，拒绝解析到私有、保留或回环 IP 的地址，以降低 SSRF 风险。连接前会校验域名解析出的全部地址，优先使用公网 IPv4，没有 IPv4 时再使用公网 IPv6。所有入站活动必须带有效的 `Digest`、`Date` 和 HTTP Signature。

## 当前限制

- 暂不联邦发送 Typecho 本地评论。
- 时间轴暂不展开远端转发活动，也不处理媒体附件、投票、私信、通知和内容警告。
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
