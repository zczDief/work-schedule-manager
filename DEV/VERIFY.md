# Verificação

Data: 2026-10-28.

## Coolify local

Stack `wsm-coolify-test`, derrubada com `docker compose down -v`.

- Build executou os checks do projeto.
- `/health.php` = `ok`, `/login` = 200.
- Reinício do `web` pulou o instalador.

## Dados de demonstração

Stack `wsm-seed-test`, derrubada ao final.

- `php tools/seed-demo.php --yes` criou 8 contas `demo.*`, 3 escalas, 12 linhas de funcionário, 73 turnos, 2 avisos, 2 confirmações, 1 alteração e convites `open` e `submitted`.
- A segunda execução sem `--force` recusou a carga.
- `--yes --force` substituiu os dados e manteve 1 administrador e 3 escalas.
- O administrador original não foi alterado.
