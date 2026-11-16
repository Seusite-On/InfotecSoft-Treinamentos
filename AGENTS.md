# Guia do projeto para IAs — InfotecSoft Treinamentos

> Leia antes de mexer no código. Mesmo padrão do projeto **Stok Net (sistema-pdv-main)**.
> Idioma: **português (Brasil)** para mensagens, labels e comentários; nomes de código (classes, métodos, colunas) em inglês.

## O que é

Portal de treinamentos em vídeo dos sistemas ERP da InfotecSoft (Fusion Web, Fusion Web 2.0, Fusion Desktop, E-Trade, Avante):
cadastro de alunos com aprovação, permissões por sistema/módulo, permissões administrativas, módulos editáveis e
aulas com vídeo do YouTube ou arquivo enviado.

## Stack

- **PHP 8.3** puro, MVC próprio em `app/Core` (copiado do Stok Net: `Route`, `Request`, `Controller`, `DB`, `QueryBuilder`, `Auth`, `CSRF`, `RateLimiter`, `View`) + `Gate` próprio.
- **MySQL** (Laragon) via PDO. Banco: `treinamentos` (`DB_DATABASE` no `.env`).
- Composer é **opcional**: o `bootstrap.php` tem autoload PSR-4 e leitor de `.env` próprios. Se rodar `composer install`, usa o `vlucas/phpdotenv`.
- **Front-end**: página única em `app/Views/app.html` (HTML/CSS/JS inline, sem npm). Fala com a API em `/api/*` via `fetch` com header `X-CSRF-TOKEN`.

## Como rodar

1. Laragon com **MySQL ligado**.
2. `.env` na raiz (`DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE=treinamentos`, `APP_PORT=8001`). Não commitar.
3. **`atualizar-banco.bat`** → `database/scripts/run_all.php` (create_db → migrate → seed do admin).
4. (opcional) **`popular-banco-teste.bat`** → usuários de teste (senha `123456`).
5. **`iniciar-servidor.bat`** → `php -S localhost:8001 -t public_html` (já com limites de upload de 2 GB). Acesse http://localhost:8001.
   - Porta 8001 para não brigar com o Stok Net (8000).
6. Login inicial: **admin / 123** (trocar em *Meu perfil*).

## Fluxo de uma requisição

```
public_html/index.php → bootstrap.php (autoload, .env, helpers, headers/CSP, sessão) → routes/web.php
→ Route::dispatch() (CSRF em POST) → Controller → Service → Repository → DB::table()/DB::select() → MySQL
```
- `GET /` entrega `app/Views/app.html`. Tudo o mais é JSON (`json_out()` / `json_fail()` em `app/Helpers/api.php`).
- `require_login('perm')` = exige login (401), confere se o usuário continua **aprovado** e checa o `Gate` (403).

## API

| Rota | Controller | Permissão |
|---|---|---|
| `GET /api/state` | StateController — usuário logado, sistemas, módulos, aulas e (equipe) usuários | — |
| `POST /api/login`, `/api/logout`, `/api/register`, `/api/password` | AuthController (rate limit 5/15 min) | — / login |
| `POST /api/users/{id}/status` | UserController — aprovar/recusar/revogar | `aprovacoes` |
| `POST /api/users/{id}/access` | UserController — módulos liberados + permissões administrativas (só o admin principal muda as administrativas) | `usuarios` |
| `POST /api/modules`, `/api/modules/{id}`, `/{id}/move`, `/{id}/delete` | ModuleController | `modulos` |
| `POST /api/lessons`, `/api/lessons/{id}/remove-video` | LessonController (multipart para arquivo) | `videos` |
| `GET /media/video/{id}`, `/media/thumb/{id}` | MediaController (Range/206; confere acesso ao módulo) | login |

## Permissões

- `users.role = 'admin'` → administrador principal, pode tudo.
- `user_permissions` (igual ao Stok Net): `can_approve_users` (aprovacoes), `can_manage_users` (usuarios), `can_manage_modules` (modulos), `can_manage_videos` (videos). Mapa em `Gate::MAP`.
- Conteúdo: `user_module_access` (usuário × módulo). Ao aprovar, libera todos os módulos do sistema pedido no cadastro.
- O aluno só recebe o vídeo (YouTube/arquivo) dos módulos liberados (`StateService`); `/media/video` também confere.

## Banco de dados

Tabelas: `systems`, `modules`, `lessons`, `users`, `user_permissions`, `user_module_access`, `migrations`.
- Alteração nova = `database/migrations/NNN_descricao.sql` com o próximo número (último: `002`). Rode `atualizar-banco.bat`.
- **Não use `;` dentro de textos** nas migrations (o `migrate.php` divide o arquivo por `;`). Não edite migration já aplicada.
- Vídeos e miniaturas ficam em `storage/videos` e `storage/thumbs` (fora da pasta pública).

## Front-end (app.html)

Gerado a partir do projeto de layout (CSS/JS separados) e copiado para `app/Views/app.html`.
Dados: `loadState()` preenche `STATE`; toda alteração chama `api()` (ou `apiUpload()` com progresso) e depois `loadState()`.

## Cuidados

- Não exibir/commitar o `.env`.
- `display_errors` está **0** (a API sempre responde JSON); erros vão para o log do PHP.
- Senhas com `password_hash()`; sessão `treinamentos_sid` (HttpOnly, SameSite=Lax).
- YouTube só toca incorporado com o portal aberto por `http://localhost` ou domínio `https://` (exigência do YouTube).
