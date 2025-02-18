<?php
  import requests
import sqlite3
from datetime import datetime, timedelta
import schedule
import time
from flask import Flask, request
import xml.etree.ElementTree as ET
from wechatpy import WeChatClient

# ================== 配置区域 ==================
WECHAT_APPID = 'gh_eb0b7f33fa94'  # 你的微信公众号APPID
WECHAT_APPSECRET = 'Zhaohs-5201314'  # 你的微信公众号APPSECRET
# =============================================

# 获取 access_token 函数
def get_access_token():
    """获取 access_token"""
    url = f"https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={WECHAT_APPID}&secret={WECHAT_APPSECRET}"
    try:
        response = requests.get(url)
        response.raise_for_status()
        data = response.json()
        if 'access_token' in data:
            return data['access_token']
        else:
            print(f"获取 access_token 失败，错误信息: {data.get('errmsg')}")
    except requests.RequestException as e:
        print(f"请求 access_token 时出错: {e}")
    return None

# 初始化微信客户端
wechat_client = WeChatClient(WECHAT_APPID, WECHAT_APPSECRET)

# ------------------ 爬虫模块 ------------------
def fetch_weibo_hot():
    """爬取微博热搜"""
    url = 'https://weibo.com/ajax/side/hotSearch'
    headers = {'User-Agent': 'Mozilla/5.0'}
    try:
        response = requests.get(url, headers=headers, timeout=10)
        data = response.json()
        return [{'keyword': item['note'], 'url': f"https://s.weibo.com/weibo?q={item['note']}"}
                for item in data['data']['realtime'][:20]]
    except Exception as e:
        print(f"爬虫失败: {str(e)}")
        return []

# ------------------ AI处理模块 ------------------
def generate_ai_summary(news_list):
    """调用DeepSeek生成摘要"""
    try:
        # 构建请求的提示信息
        prompt = "请将以下20条微博热搜整理为10条最关键的新闻，每条格式：1. [分类] 关键词（热度说明）"
        news_text = "\n".join([f"{i + 1}. {item['keyword']}" for i, item in enumerate(news_list)])
        full_prompt = prompt + "\n" + news_text

        # 构建请求数据
        data = {
            "model": "deepseek-r1:1.5b",  # 确保模型名称与你本地部署的一致
            "prompt": full_prompt,
            "max_tokens": 500
        }

        # 向ollama的API发送POST请求
        response = requests.post("http://localhost:11434/api/generate", json=data)

        # 检查响应状态
        if response.status_code == 200:
            result = response.json()
            return result["response"].strip()
        else:
            print(f"请求失败，状态码: {response.status_code}")
            return "今日热点生成失败，请稍后再试"
    except Exception as e:
        print(f"AI处理失败: {str(e)}")
        return "今日热点生成失败，请稍后再试"

# ------------------ 数据库模块 ------------------
def init_db():
    """初始化数据库"""
    conn = sqlite3.connect('weibo_news.db')
    c = conn.cursor()
    c.execute('''CREATE TABLE IF NOT EXISTS news
                (date TEXT PRIMARY KEY, content TEXT)''')
    conn.commit()
    conn.close()

def save_daily_news():
    """每日保存数据"""
    today = datetime.now().strftime("%Y-%m-%d")
    news_list = fetch_weibo_hot()
    if not news_list:
        return
    summary = generate_ai_summary(news_list)
    conn = sqlite3.connect('weibo_news.db')
    c = conn.cursor()
    c.execute("INSERT OR REPLACE INTO news VALUES (?,?)", (today, summary))
    conn.commit()
    conn.close()

# ------------------ 微信交互模块 ------------------
app = Flask(__name__)

@app.route('/wechat', methods=['GET', 'POST'])
def wechat_handler():
    """处理微信消息"""
    if request.method == 'GET':
        # 验证服务器配置
        signature = request.args.get('signature', '')
        timestamp = request.args.get('timestamp', '')
        nonce = request.args.get('nonce', '')
        echostr = request.args.get('echostr', '')
        # 此处需实现校验逻辑（示例直接返回echostr）
        return echostr
    else:
        # 处理用户消息
        xml_data = request.data
        root = ET.fromstring(xml_data)
        user_msg = root.find('Content').text.strip()
        from_user = root.find('FromUserName').text

        # 判断消息类型
        if user_msg.lower() in ['今日', '今天']:
            date = datetime.now().strftime("%Y-%m-%d")
        elif user_msg.lower() == '昨日':
            date = (datetime.now() - timedelta(days=1)).strftime("%Y-%m-%d")
        else:
            date = user_msg  # 假设用户直接发送日期

        # 查询数据库
        conn = sqlite3.connect('weibo_news.db')
        c = conn.cursor()
        c.execute("SELECT content FROM news WHERE date=?", (date,))
        result = c.fetchone()
        reply = f"【{date} 微博热点】\n{result[0]}" if result else "暂无该日期记录"

        # 返回XML格式消息
        return f'''
        <xml>
          <ToUserName><![CDATA[{from_user}]]></ToUserName>
          <FromUserName><![CDATA[{WECHAT_APPID}]]></FromUserName>
          <CreateTime>{int(time.time())}</CreateTime>
          <MsgType><![CDATA[text]]></MsgType>
          <Content><![CDATA[{reply}\n输入日期格式如：2024-03-15]]></Content>
        </xml>
        '''

# ------------------ 定时任务模块 ------------------
def job_daily_push():
    """每日18点推送"""
    today = datetime.now().strftime("%Y-%m-%d")
    conn = sqlite3.connect('weibo_news.db')
    c = conn.cursor()
    c.execute("SELECT content FROM news WHERE date=?", (today,))
    content = c.fetchone()[0] if c.fetchone() else "今日热点生成中，请稍后查询"

    # 获取 access_token
    access_token = get_access_token()
    if access_token:
        # 这里可以使用 access_token 调用需要的微信接口，当前代码使用了 wechatpy 库，可根据实际情况调整
        wechat_client = WeChatClient(WECHAT_APPID, access_token=access_token)
        wechat_client.message.send_text(
            user_id="@all",
            content=f"【{today} 热点速递】\n{content}\n回复日期查看历史"
        )

# ------------------ 主程序 ------------------
if __name__ == "__main__":
    init_db()

    # 启动定时任务
    schedule.every().day.at("17:50").do(save_daily_news)  # 提前抓取
    schedule.every().day.at("18:00").do(job_daily_push)

    # 启动Flask服务（需公网IP+备案域名）
    from threading import Thread
    Thread(target=lambda: app.run(port=80, host='0.0.0.0')).start()

    # 保持主线程运行
    while True:
        schedule.run_pending()
        time.sleep(1)

?>
