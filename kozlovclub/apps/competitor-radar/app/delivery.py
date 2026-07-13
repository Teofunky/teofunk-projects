from __future__ import annotations
import smtplib
from email.mime.text import MIMEText
import httpx
from .config import settings


def short_text(report: dict) -> str:
    e=report["executive"]
    lines=[f"КОНКУРЕНТНЫЙ РАДАР · {report['date_from']}—{report['date_to']}",""]
    lines += ["ГЛАВНОЕ:"]+[f"• {x['text']}" for x in e["facts"][:4]]
    lines += ["","РИСКИ:"]+[f"• {x['text']}" for x in e["risks"][:4]]
    lines += ["","ДЕЙСТВИЯ:"]+[f"{i+1}. {x}" for i,x in enumerate(e["actions"][:4])]
    return "\n".join(lines)[:3900]

async def send_telegram(report: dict) -> dict:
    if not settings.telegram_bot_token or not settings.telegram_chat_id:
        return {"sent":False,"reason":"TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID не настроены"}
    url=f"https://api.telegram.org/bot{settings.telegram_bot_token}/sendMessage"
    async with httpx.AsyncClient(timeout=20) as client:
        r=await client.post(url,json={"chat_id":settings.telegram_chat_id,"text":short_text(report),"disable_web_page_preview":True})
        r.raise_for_status()
    return {"sent":True}

def send_email(report: dict, markdown: str) -> dict:
    if not settings.smtp_host or not settings.report_email_to:
        return {"sent":False,"reason":"SMTP_HOST/REPORT_EMAIL_TO не настроены"}
    msg=MIMEText(markdown,"plain","utf-8")
    msg["Subject"]=f"Конкурентный радар {report['date_from']}—{report['date_to']}"
    msg["From"]=settings.smtp_user or "competitor-radar@localhost"
    msg["To"]=settings.report_email_to
    with smtplib.SMTP(settings.smtp_host,settings.smtp_port,timeout=25) as server:
        server.starttls()
        if settings.smtp_user: server.login(settings.smtp_user,settings.smtp_password)
        server.send_message(msg)
    return {"sent":True}
