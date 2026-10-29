# Contexto

- Upstream: `https://github.com/QvarcY/work-schedule-manager` (remote `upstream`).
- Fork: `https://github.com/zczDief/work-schedule-manager` (remote `origin`).
- Licença: AGPL-3.0-or-later, com termos extras em `ADDITIONAL_TERMS.md`. A cópia precisa continuar identificada como versão modificada.
- App PHP sem Composer. Document root: `public/`. Banco: MariaDB/MySQL.
- Coolify lê `docker-compose.yml` na raiz. Serviço público: `web`, porta 80. Banco: `mariadb`, sem porta publicada.
- O instalador em `tools/install.php` só serve para banco vazio. O entrypoint pula a instalação quando `users` já tem contas.
- Senha inicial: `ADMIN_PASSWORD` ou senha gerada, copiada para `storage/initial-admin-credentials.txt` (root, modo 600).
- Volumes: `web-storage` e `mariadb-data`. Módulos enviados para `modules/` não sobrevivem a um rebuild.
