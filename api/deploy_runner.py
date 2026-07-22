import sys
import os
import json
# Insert local API folder into path so python can find the locally installed paramiko
sys.path.insert(0, os.path.dirname(__file__))
import paramiko

def deploy(ip, password, code, project_type):
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        ssh.connect(ip, username="root", password=password, timeout=15)
    except Exception as e:
        return {"status": "error", "message": f"Serverga ulanib bo'lmadi (SSH error): {e}"}

    try:
        if project_type == "python-bot":
            # 1. Create directory /root/my_telegram_bot
            ssh.exec_command("mkdir -p /root/my_telegram_bot")
            
            # 2. Write the code to /root/my_telegram_bot/bot.py
            transport = ssh.get_transport()
            sftp = paramiko.SFTPClient.from_transport(transport)
            
            # Write bot script
            with sftp.open("/root/my_telegram_bot/bot.py", "w") as f:
                f.write(code)
            
            # Install python3-pip & dependencies asynchronously (wait for it)
            ssh.exec_command("apt-get update && apt-get install -y python3-pip")
            stdin, stdout, stderr = ssh.exec_command("pip3 install python-telegram-bot pyTelegramBotAPI requests")
            stdout.read() # blocking wait to ensure it finishes before start
            
            # 3. Register systemd service to run it 24/7
            service_content = """[Unit]
Description=My Telegram Bot
After=network.target

[Service]
Type=simple
WorkingDirectory=/root/my_telegram_bot
ExecStart=/usr/bin/python3 bot.py
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
"""
            with sftp.open("/etc/systemd/system/telegram-bot.service", "w") as f:
                f.write(service_content)
                
            ssh.exec_command("systemctl daemon-reload")
            ssh.exec_command("systemctl enable telegram-bot.service")
            stdin, stdout, stderr = ssh.exec_command("systemctl restart telegram-bot.service")
            stdout.read() # Wait for restart
            
            sftp.close()
            ssh.close()
            return {"status": "success", "message": "Bot serverga 24/7 rejimda muvaffaqiyatli joylashtirildi va ishga tushirildi! Botga o'tib /start bosing."}
            
        elif project_type == "html-site":
            # Install apache2
            stdin, stdout, stderr = ssh.exec_command("apt-get update && apt-get install -y apache2")
            stdout.read() # Wait for install
            
            # Write HTML file directly to apache webroot
            transport = ssh.get_transport()
            sftp = paramiko.SFTPClient.from_transport(transport)
            with sftp.open("/var/www/html/index.html", "w") as f:
                f.write(code)
            sftp.close()
            ssh.close()
            return {"status": "success", "message": f"Veb-sayt muvaffaqiyatli serverga joylandi! Uni ko'rish uchun server IP raqamini brauzerda oching: http://{ip}/"}
            
        else:
            ssh.close()
            return {"status": "error", "message": "Noma'lum loyiha turi."}
    except Exception as e:
        ssh.close()
        return {"status": "error", "message": f"Joylashtirishda xatolik yuz berdi: {e}"}

if __name__ == "__main__":
    # Read JSON input from stdin
    try:
        input_data = json.loads(sys.stdin.read())
        res = deploy(input_data["ip"], input_data["password"], input_data["code"], input_data["type"])
        print(json.dumps(res))
    except Exception as e:
        print(json.dumps({"status": "error", "message": str(e)}))
