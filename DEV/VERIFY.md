# Verificação

Data: 2026-10-28. Stack local `wsm-coolify-test`, derrubada com `docker compose down -v` ao final.

- Build da imagem executou `install.php --check`, `check-migrations.php`, `check-translations.php` e `tests/translator.php`.
- Com `ADMIN_PASSWORD`: instalação completa, `/health.php` = `ok`, `/login` = 200.
- Sem `ADMIN_PASSWORD`: senha gerada, arquivo `storage/initial-admin-credentials.txt` em `600 root:root`, `.env` em `640 root:www-data`.
- Reinício do `web`: log `Installation already present (1 user account(s)). Skipping installer.` e `/health.php` = `ok`.
- `/login` contém um campo de senha.

Não verificado no Coolify em si, nem o fluxo de login com a senha no navegador.
