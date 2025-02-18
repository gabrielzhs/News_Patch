<?php
// ================== 配置区域 ==================
define('WECHAT_APPID', 'gh_eb0b7f33fa94');
define('WECHAT_APPSECRET', 'Zhaohs-5201314');
// =============================================

// 获取 access_token 函数
function get_access_token() {
    $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=". WECHAT_APPID . "&secret=". WECHAT_APPSECRET;
    $response = file_get_contents($url);
    if ($response === false) {
        echo "请求 access_token 失败";
        return null;
    }
    $data = json_decode($response, true);
    if (isset($data['access_token'])) {
        return $data['access_token'];
    } else {
        echo "获取 access_token 失败，错误信息: ". ($data['errmsg']?? '未知错误');
        return null;
    }
}

// 爬虫模块
function fetch_weibo_hot() {
    $url = 'https://weibo.com/ajax/side/hotSearch';
    $headers = [
        'User-Agent: Mozilla/5.0'
    ];
    $context = stream_context_create([
        'http' => [
            'header' => implode("\r\n", $headers),
            'timeout' => 10
        ]
    ]);
    $response = file_get_contents($url, false, $context);
    if ($response === false) {
        echo "爬虫失败: 请求微博热搜数据失败";
        return [];
    }
    $data = json_decode($response, true);
    if ($data === null) {
        echo "爬虫失败: 解析微博热搜数据失败";
        return [];
    }
    $hot_list = [];
    foreach (array_slice($data['data']['realtime'], 0, 20) as $item) {
        $hot_list[] = [
            'keyword' => $item['note'],
            'url' => 'https://s.weibo.com/weibo?q='. urlencode($item['note'])
        ];
    }
    return $hot_list;
}

// AI 处理模块
function generate_ai_summary($news_list) {
    $prompt = "请将以下20条微博热搜整理为10条最关键的新闻，每条格式：1. [分类] 关键词（热度说明）";
    $news_text = "";
    foreach ($news_list as $i => $item) {
        $news_text .= ($i + 1). ". ". $item['keyword']. "\n";
    }
    $full_prompt = $prompt. "\n". $news_text;
    $data = [
        "model" => "deepseek-r1:1.5b",
        "prompt" => $full_prompt,
        "max_tokens" => 500
    ];
    $options = [
        'http' => [
            'method' => 'POST',
            'header' => "Content-type: application/json\r\n",
            'content' => json_encode($data)
        ]
    ];
    $context = stream_context_create($options);
    $response = file_get_contents("http://localhost:11434/api/generate", false, $context);
    if ($response === false) {
        echo "AI 处理失败: 请求摘要生成失败";
        return "今日热点生成失败，请稍后再试";
    }
    $result = json_decode($response, true);
    if ($result === null) {
        echo "AI 处理失败: 解析摘要生成结果失败";
        return "今日热点生成失败，请稍后再试";
    }
    return trim($result['response']);
}

// 数据库模块
function init_db() {
    $db = new SQLite3('weibo_news.db');
    $query = 'CREATE TABLE IF NOT EXISTS news (date TEXT PRIMARY KEY, content TEXT)';
    $db->exec($query);
    $db->close();
}

function save_daily_news() {
    $today = date('Y-m-d');
    $news_list = fetch_weibo_hot();
    if (empty($news_list)) {
        return;
    }
    $summary = generate_ai_summary($news_list);
    $db = new SQLite3('weibo_news.db');
    $stmt = $db->prepare('INSERT OR REPLACE INTO news (date, content) VALUES (:date, :content)');
    $stmt->bindValue(':date', $today);
    $stmt->bindValue(':content', $summary);
    $stmt->execute();
    $db->close();
}

// 微信交互模块
function wechat_handler() {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $signature = $_GET['signature']?? '';
        $timestamp = $_GET['timestamp']?? '';
        $nonce = $_GET['nonce']?? '';
        $echostr = $_GET['echostr']?? '';
        // 此处需实现校验逻辑（示例直接返回echostr）
        return $echostr;
    } else {
        $xml_data = file_get_contents('php://input');
        $xml = simplexml_load_string($xml_data);
        $user_msg = trim((string)$xml->Content);
        $from_user = (string)$xml->FromUserName;
        if (in_array(strtolower($user_msg), ['今日', '今天'])) {
            $date = date('Y-m-d');
        } elseif (strtolower($user_msg) === '昨日') {
            $date = date('Y-m-d', strtotime('-1 day'));
        } else {
            $date = $user_msg;
        }
        $db = new SQLite3('weibo_news.db');
        $stmt = $db->prepare('SELECT content FROM news WHERE date = :date');
        $stmt->bindValue(':date', $date);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $reply = $row? "【{$date} 微博热点】\n{$row['content']}" : "暂无该日期记录";
        $db->close();
        return "<xml>
          <ToUserName><![CDATA[{$from_user}]]></ToUserName>
          <FromUserName><![CDATA[". WECHAT_APPID . "]]></FromUserName>
          <CreateTime>". time() . "</CreateTime>
          <MsgType><![CDATA[text]]></MsgType>
          <Content><![CDATA[{$reply}\n输入日期格式如：2024-03-15]]></Content>
        </xml>";
    }
}

// 定时任务模块（PHP 本身没有内置定时任务，可借助系统的定时任务工具如 cron）
function job_daily_push() {
    $today = date('Y-m-d');
    $db = new SQLite3('weibo_news.db');
    $stmt = $db->prepare('SELECT content FROM news WHERE date = :date');
    $stmt->bindValue(':date', $today);
    $result = $stmt->execute();
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $content = $row? $row['content'] : "今日热点生成中，请稍后查询";
    $db->close();
    $access_token = get_access_token();
    if ($access_token) {
        // 这里可以使用 access_token 调用需要的微信接口，当前代码使用了 wechatpy 库，可根据实际情况调整
        // 由于 PHP 没有直接对应的 wechatpy 库，需要手动实现微信消息发送逻辑
        // 示例代码省略，可参考微信官方文档实现
    }
}

// 主程序
init_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    echo wechat_handler();
}

// 以下定时任务逻辑需要借助系统的定时任务工具（如 cron）来实现
// save_daily_news();
// job_daily_push();
?>
