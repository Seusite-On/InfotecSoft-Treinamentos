# Publicação automática: PC → GitHub → VPS da Hostinger

Cada `git push` na branch `main` dispara o workflow `.github/workflows/deploy.yml`, que:
1. confere a sintaxe do PHP;
2. envia os arquivos para a VPS (rsync via SSH) — sem mexer no `.env`, nos vídeos enviados nem nas miniaturas;
3. roda `database/scripts/run_all.php` (aplica migrations novas) e `tests/smoke.php`.

## 1. Criar o repositório no GitHub
GitHub → **New repository** → nome `infotecsoft-treinamentos` → **Private** → sem README → Create.

## 2. Enviar o projeto pela primeira vez (no PC)
Abra o terminal na pasta `C:\InfotecSoft-Treinamentos` (no Laragon: botão **Terminal**, depois `cd C:\InfotecSoft-Treinamentos`):
```
mkdir .github\workflows
copy deploy\deploy.yml .github\workflows\deploy.yml
git init -b main
git add -A
git commit -m "Portal de treinamentos - versão inicial"
git remote add origin https://github.com/SEU-USUARIO/infotecsoft-treinamentos.git
git push -u origin main
```
(O `.env` com a senha do banco NÃO vai para o GitHub — está no `.gitignore`.)

## 3. Preparar a VPS (uma vez)
1. Hostinger → **hPanel → VPS** → anote o **IP** e a senha de root.
2. No seu domínio: **DNS** → registro **A** `@` (e `www`) apontando para o IP da VPS.
3. Copie o script para a VPS e rode (no terminal do PC):
```
scp deploy\setup-vps.sh root@IP-DA-VPS:/root/
ssh root@IP-DA-VPS "bash /root/setup-vps.sh"
```
   (ou abra o **Terminal do navegador** no hPanel, crie o arquivo com `nano setup-vps.sh`, cole o conteúdo e rode `bash setup-vps.sh`)
4. O script pede o domínio e um e-mail, instala Nginx + PHP 8.3 + MySQL, cria o banco, o `.env` do servidor,
   o HTTPS e, no final, **mostra uma chave privada**.

## 4. Configurar os secrets no GitHub
Repositório → **Settings → Secrets and variables → Actions → New repository secret**:

| Nome | Valor |
|---|---|
| `VPS_HOST` | IP da VPS |
| `VPS_USER` | `deploy` |
| `VPS_PORT` | `22` |
| `APP_DIR` | `/var/www/treinamentos` |
| `VPS_SSH_KEY` | a chave privada mostrada no fim do setup (de `-----BEGIN` até `END-----`) |

Depois apague a chave da VPS: `rm /root/github_deploy_key`.

## 5. Publicar
GitHub → aba **Actions** → "Publicar na VPS (Hostinger)" → **Run workflow** (ou faça qualquer `git push`).
Quando ficar verde, acesse `https://seu-dominio`. Login inicial: `admin` / `123` (troque em *Meu perfil*).

## Dia a dia
Alterou algo? Dê dois cliques em **`enviar-alteracoes.bat`** (ou `git add -A`, `git commit -m "..."`, `git push`).
Em ~1 minuto está no ar. Se der erro, a aba **Actions** do GitHub mostra o motivo.

## Banco de dados
- Mudança de estrutura = novo arquivo `database/migrations/003_....sql`. O deploy aplica sozinho.
- Os dados do servidor (usuários, vídeos enviados) ficam só na VPS; o banco do seu PC é separado (teste).
