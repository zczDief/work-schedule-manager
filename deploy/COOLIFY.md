# Deploy no Coolify

Este fork empacota o Work Schedule Manager para o Coolify. A stack sobe o PHP 8.3 com Apache (raiz pública em `public/`) e o MariaDB 11.4. O instalador roda só na primeira inicialização, quando o banco ainda não tem usuários.

O projeto original é de QvarcY: https://github.com/QvarcY/work-schedule-manager. Esta cópia é uma versão modificada para deploy e não é um release oficial.

## No Coolify

1. Crie um recurso a partir do repositório `zczDief/work-schedule-manager`, branch `main`.
2. Em Build, escolha **Docker Compose**.
3. Base Directory: `/`
4. Docker Compose Location: `/docker-compose.yml`
5. Salve para o Coolify ler o arquivo. Ele cria as variáveis `SERVICE_URL_WEB`, `SERVICE_USER_MARIADB`, `SERVICE_PASSWORD_64_MARIADB` e `SERVICE_PASSWORD_64_MARIADBROOT`.
6. Em Domains, publique o serviço `web` na porta interna **80**. Com domínio curinga, `SERVICE_URL_WEB` já aponta para esse serviço.
7. Opcional: defina `ADMIN_USERNAME`, `ADMIN_EMAIL` e `ADMIN_PASSWORD` (mínimo de 12 caracteres) antes do primeiro deploy. Se `ADMIN_PASSWORD` ficar vazio, o instalador gera uma senha.
8. Faça o deploy. Use uma única réplica do serviço `web`.

Não publique a porta do MariaDB. Os dois serviços se enxergam pelo nome `mariadb` na rede do Compose.

## Primeiro acesso

Se você definiu `ADMIN_PASSWORD`, entre com `ADMIN_USERNAME` (padrão `admin`) e essa senha.

Se a senha foi gerada, ela aparece uma vez nos logs do serviço `web`, na linha `Administrator password:`. A mesma senha fica em `storage/initial-admin-credentials.txt` dentro do container, com permissão de leitura só para root. Apague o arquivo depois de guardar a senha:

```bash
docker exec <container-web> rm -f /var/www/html/storage/initial-admin-credentials.txt
```

Troque a senha na aplicação depois do primeiro login.

## O que persiste

- `web-storage`: traduções importadas e o arquivo de credenciais inicial.
- `mariadb-data`: o banco.

Um novo deploy reaproveita esses volumes e não roda o instalador de novo. Módulos enviados pela interface gravados em `modules/` fazem parte da imagem e somem no próximo build. As traduções em `storage/lang/` permanecem.

## Variáveis úteis

| Variável | Padrão | Função |
| --- | --- | --- |
| `APP_URL` | `SERVICE_URL_WEB` | URL pública HTTPS. O cookie de sessão fica seguro quando a URL começa com `https://`. |
| `APP_LOCALE` | `lv` | Idioma inicial. Use `en` para inglês. |
| `APP_DEBUG` | `false` | Mantenha `false` em produção. |
| `ADMIN_PASSWORD` | vazio | Senha do primeiro administrador. Vazio gera uma senha. |
| `EMAIL_NOTIFICATIONS_ENABLED` | `false` | Liga o envio de e-mail quando houver SMTP acessível pelo PHP `mail()`. |

## Verificação local

Na raiz do repositório, com Docker:

```bash
export SERVICE_URL_WEB=http://localhost
export SERVICE_USER_MARIADB=scheduler
export SERVICE_PASSWORD_64_MARIADB=local-test-password-123456
export SERVICE_PASSWORD_64_MARIADBROOT=local-root-password-123456
export ADMIN_USERNAME=admin
export ADMIN_PASSWORD=local-admin-password

docker compose up --build -d
docker compose exec web curl --fail --silent http://127.0.0.1/health.php
docker compose down -v
```

Esse teste não publica porta no host. O `curl` roda dentro do container. `down -v` apaga os volumes dessa prova.
