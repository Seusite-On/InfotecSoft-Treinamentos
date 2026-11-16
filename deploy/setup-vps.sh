#!/usr/bin/env bash
# =====================================================================
# Prepara a VPS da Hostinger (Ubuntu 22.04/24.04) para o Portal de Treinamentos.
# Rode UMA vez como root:   bash setup-vps.sh
# Instala Nginx + PHP 8.3 + MySQL, cria o banco, o usuário "deploy",
# o .env do servidor, o site do domínio com HTTPS e a chave para o GitHub.
# =====================================================================
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Rode como root (sudo bash setup-vps.sh)"; exit 1; }

APP_DIR=/var/www/treinamentos
DB_NAME=treinamentos
DB_USER=treinamentos
DEPLOY_USER=deploy

read -rp "Domínio do portal (ex.: treinamentos.suaempresa.com.br): " DOMAIN
read -rp "E-mail para o certificado HTTPS (Let's Encrypt): " EMAIL
[ -n "$DOMAIN" ] || { echo "Domínio obrigatório"; exit 1; }

echo "==> Instalando pacotes..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
. /etc/os-release
if [ "${VERSION_ID%%.*}" -lt 24 ]; then
  apt-get install -y software-properties-common
  add-apt-repository -y ppa:ondrej/php
  apt-get update -y
fi
apt-get install -y nginx mysql-server rsync curl unzip git certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd

echo "==> Usuário de publicação ($DEPLOY_USER)..."
id "$DEPLOY_USER" >/dev/null 2>&1 || adduser --disabled-password --gecos "" "$DEPLOY_USER"
usermod -aG www-data "$DEPLOY_USER"
mkdir -p "$APP_DIR"/storage/{videos,thumbs,security}
chown -R "$DEPLOY_USER":www-data "$APP_DIR"
chmod 2775 "$APP_DIR" "$APP_DIR"/storage "$APP_DIR"/storage/{videos,thumbs,security}

echo "==> Banco de dados..."
if [ ! -f "$APP_DIR/.env" ]; then
  DB_PASS=$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)
  mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
            CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
            ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
            GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;"
  cat > "$APP_DIR/.env" <<ENV
DB_HOST=localhost
DB_PORT=3306
DB_USERNAME=$DB_USER
DB_PASSWORD=$DB_PASS
DB_DATABASE=$DB_NAME
APP_PORT=80
UPLOAD_MAX_MB=2048
ENV
  chown "$DEPLOY_USER":www-data "$APP_DIR/.env"; chmod 640 "$APP_DIR/.env"
  echo "    .env criado em $APP_DIR/.env (senha do banco gerada automaticamente)."
else
  echo "    .env já existe — mantido."
fi

echo "==> PHP (limites para envio de vídeos)..."
cat > /etc/php/8.3/fpm/conf.d/99-treinamentos.ini <<'INI'
upload_max_filesize = 2048M
post_max_size = 2100M
max_execution_time = 600
max_input_time = 600
memory_limit = 512M
expose_php = Off
INI
systemctl restart php8.3-fpm

echo "==> Nginx ($DOMAIN)..."
cat > /etc/nginx/sites-available/treinamentos <<NGINX
server {
    listen 80;
    server_name $DOMAIN www.$DOMAIN localhost;
    root $APP_DIR/public_html;
    index index.php;
    client_max_body_size 2100M;
    client_body_timeout 600s;

    location ~ /\. { deny all; }
    location / { try_files \$uri /index.php?\$query_string; }
    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 600s;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/treinamentos /etc/nginx/sites-enabled/treinamentos
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> Chave SSH para o GitHub publicar..."
KEY=/root/github_deploy_key
[ -f "$KEY" ] || ssh-keygen -t ed25519 -N "" -C "github-actions-treinamentos" -f "$KEY" >/dev/null
install -d -m 700 -o "$DEPLOY_USER" -g "$DEPLOY_USER" /home/$DEPLOY_USER/.ssh
grep -qf "$KEY.pub" /home/$DEPLOY_USER/.ssh/authorized_keys 2>/dev/null || cat "$KEY.pub" >> /home/$DEPLOY_USER/.ssh/authorized_keys
chown "$DEPLOY_USER":"$DEPLOY_USER" /home/$DEPLOY_USER/.ssh/authorized_keys; chmod 600 /home/$DEPLOY_USER/.ssh/authorized_keys

echo "==> HTTPS (Let's Encrypt)..."
if certbot --nginx -n --agree-tos -m "$EMAIL" -d "$DOMAIN" --redirect; then
  echo "    HTTPS ativo."
else
  echo "    [!] Não foi possível ativar o HTTPS agora. Confira se o DNS do domínio aponta para este IP"
  echo "        e rode depois:  certbot --nginx -d $DOMAIN --redirect"
fi
command -v ufw >/dev/null && { ufw allow OpenSSH >/dev/null; ufw allow 'Nginx Full' >/dev/null; } || true

IP=$(curl -s https://api.ipify.org || hostname -I | awk '{print $1}')
echo
echo "=================================================================="
echo " PRONTO! Agora, no GitHub (Settings > Secrets and variables > Actions)"
echo " crie estes secrets:"
echo
echo "   VPS_HOST    = $IP"
echo "   VPS_USER    = $DEPLOY_USER"
echo "   VPS_PORT    = 22"
echo "   APP_DIR     = $APP_DIR"
echo "   VPS_SSH_KEY = (copie TUDO abaixo, da linha BEGIN até a linha END)"
echo "------------------------------------------------------------------"
cat "$KEY"
echo "------------------------------------------------------------------"
echo " Depois de copiar a chave, apague-a do servidor:  rm $KEY"
echo " Em seguida faça um 'git push' (ou rode o workflow no GitHub) para publicar."
echo "=================================================================="
