# Guia do projeto — InfotecSoft Treinamentos (leia antes de mexer)

> Para IAs e desenvolvedores. Idioma do portal, mensagens e comentários: **português (Brasil)**. Nomes de código (classes, métodos, colunas): inglês.
> No ar em **https://zenim.me** (Hostinger). Pasta no PC: `C:\InfotecSoft-Treinamentos`.

## O que é
Portal de videoaulas dos sistemas que a InfotecSoft atende. Sistemas atuais: Fusion Web, Fusion Desktop, E-Trade, Avante, Balanças e Conhecimento fiscal (sistemas novos são criados pelo próprio portal). Os vídeos ficam no **YouTube** e tocam dentro do portal; envio de arquivo de vídeo só para conteúdo que não pode ir ao YouTube (o espaço da hospedagem é limitado).

Funcionalidades: cadastro por CNPJ de empresa cadastrada + aprovação; acesso por sistema/módulo (ou sistema "aberto para todos"); cargos e permissões (aprovacoes, usuarios, modulos, videos, relatorios) e **super usuário**; reset de senha para `12345678` com troca obrigatória (vence em 48 h); sistemas, módulos, vídeos e provas editáveis pelo site; **pontuação** (+10 vídeo assistido até 80% e com metade da duração decorrida, +30 prova aprovada na 1ª vez, +5 vídeo novo postado por qualquer usuário); ranking/relatório com CSV; progresso por usuário ("Continue de onde parou", "Meu progresso", "Progresso da equipe"); notificações de vídeo novo (lidas por vídeo); sugestões; últimos acessos; aviso de versão nova; tema claro/escuro; proteção anti-cópia.

## Estrutura
```
app/Core/         MVC próprio: Route, Request, DB (+QueryBuilder), Auth, CSRF, RateLimiter, Gate (MAP permissão→coluna)
app/Config/       Database.php (PDO; faz SET time_zone = fuso do PHP — o MySQL da Hostinger está em UTC)
app/Controllers/  Auth, State, User, Module, System, Lesson, Media, Progress (pontos/notificações), Quiz, Report, Suggestion, Company, App
app/Services/     regras: AuthService, UserService, LessonService (busca data do YouTube), StateService (monta /api/state), AccessLog
app/Repositories/ acesso a dados (UserRepository::moduleIds inclui sistemas abertos para todos)
app/Helpers/      api.php (json_out/json_fail/require_login), security.php
app/Views/app.html  FRONT-END COMPILADO (minificado + ofuscado). NÃO editar à mão: edite frontend/src e compile.
routes/web.php    todas as rotas /api/*
database/migrations/NNN_nome.sql  migrações numeradas (registradas na tabela de migrações)
database/scripts/ migrate.php, seed.php, run_all.php, set_admin_password.php (CLI, senha por stdin)
tests/api_test.php  ~190 testes de API (precisa de banco de teste); tests/smoke.php roda no servidor
frontend/         FONTE das telas (fica só no PC: não vai para o servidor nem para o Git)
  src/            head.html, body.html, robot.html, styles.css, features.css, mobile.css, theme.css, polish.css,
                  protect.js (anti-cópia/devtools), app.js (SPA inteira), fx.js, mobile.js, theme.js, logos .png
  build.py        junta tudo em dist/infotecsoft-treinamentos.html
  tools/minify.js minifica + ofusca → dist/app.min.html
  compilar-frontend.bat  build + minify + copia para app/Views/app.html
deploy/servidor.local.json  dados do servidor (host, porta, usuário, pastas, banco). Só no PC, no .gitignore.
publicar-hostinger.bat/.ps1  publica tudo (ver abaixo)
```

## Comandos
| O que | Como |
|---|---|
| Compilar as telas | `frontend\compilar-frontend.bat` (ou `python build.py` + `node tools/minify.js dist/infotecsoft-treinamentos.html dist/app.min.html` e copiar para `app/Views/app.html`) |
| Publicar no zenim.me | `publicar-hostinger.bat` — empacota (sem frontend/, .git, deploy/, .env, storage), envia por SSH (chave `%USERPROFILE%\.ssh\zenim_deploy`), roda migrate + seed + smoke no servidor, testa `/api/state`, faz commit/push no GitHub. Log em `storage\publicar_log.txt` |
| Trocar senha do admin no servidor | `definir-senha-admin.bat` (a pessoa digita a senha; nunca automatizar) |
| Rodar local | Laragon com MySQL ligado → `atualizar-banco.bat` → `iniciar-servidor.bat` → http://localhost:8001 |
| Testes de API | `php tests/api_test.php http://127.0.0.1:PORTA` (banco de teste; admin local com senha `123`, limpe `storage/security/rate_limits.json` antes) |

## Regras importantes (aprendidas)
- **Migrações**: arquivos `.sql` separados por `;` — **nunca** use `;` dentro de comentários ou textos. Use `INSERT IGNORE`/`NOT EXISTS` para serem seguras de rodar de novo. Para achar módulos use `system_id` + `name` (os IDs mudam entre local e produção).
- **Datas**: grave horários com `now()` do PHP. O banco usa o fuso do PHP pela conexão; nada pode ficar "no futuro".
- **Vídeos do YouTube**: a data de publicação é buscada da página do vídeo (`"uploadDate"`). Em migração, preencha `published_at`, `duration`, `video_source='youtube'`, `youtube_id`, `origin='admin'`, `video_updated_at`.
- **Acesso**: aluno só recebe vídeo dos módulos liberados (ou de sistema `is_public=1`). Equipe com `videos`/`modulos` vê tudo.
- **Pontos**: total = `lesson_views.points` + `quiz_attempts.points` + `lessons.post_points` (autor). Conclusão é atômica (`UPDATE ... WHERE completed_at IS NULL`); prova usa `GET_LOCK`.
- **Limites (RateLimiter, arquivo JSON com flock)**: várias pessoas na mesma rede → limites por IP são folgados (cadastro 100/h, login com erro 60/15 min).
- **CSP**: scripts só de self + youtube.com/s.ytimg.com. Iframe do YouTube via IFrame API (host youtube-nocookie).
- **Segurança**: nunca digitar/colar senhas, chaves ou tokens por automação; nunca commitar `.env`, `deploy/servidor.local.json` ou chaves. Não testar `admin/123` no site real (só funciona em localhost).
- Depois de mudar o front: compilar → publicar. Os usuários com o portal aberto recebem o aviso "O portal foi atualizado".
