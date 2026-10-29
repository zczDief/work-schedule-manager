# Spec ativa

## Status

- Phase: ship
- Status: fork criado e stack Coolify verificada localmente
- Next gate: primeiro deploy no Coolify com domínio HTTPS
- Started at: 2026-10-28

## Objetivo

Publicar este fork no Coolify com PHP, Apache e MariaDB, instalando a aplicação uma vez.

## Aceite

- Repositório `zczDief/work-schedule-manager` existe e aponta como `origin`.
- `docker compose up` sobe `web` saudável e `mariadb` saudável.
- A primeira subida instala o schema e cria o administrador.
- Uma reinicialização não roda o instalador de novo.
- `GET /health.php` responde `ok` e `GET /login` responde 200.
