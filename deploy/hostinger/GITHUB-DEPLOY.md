# Deploying BrandMate from GitHub

Every push to `main` runs **CI** (tests and build). When CI passes, **Deploy to
Hostinger** (`.github/workflows/deploy.yml`) builds the Angular app and copies it
and the Laravel code to the server over SSH. You can also start it by hand:
GitHub > Actions > Deploy to Hostinger > Run workflow.

On the server:

```
<DEPLOY_PATH>/
  public_html/     Angular files, index.php, .htaccess
  brandmate-app/   Laravel code and .env (not reachable from the internet)
```

## One-time setup

1. **SSH key for GitHub.** On your PC:

   ```powershell
   ssh-keygen -t ed25519 -f $HOME\.ssh\brandmate_deploy -N '""' -C "github-deploy"
   ```

   In hPanel > Advanced > SSH Access > SSH keys, add the contents of
   `brandmate_deploy.pub`.

2. **Repository secrets.** GitHub > the repository > Settings > Secrets and
   variables > Actions > New repository secret. Add:

   | Secret | Example |
   |---|---|
   | `HOSTINGER_SSH_HOST` | the IP shown in hPanel SSH Access |
   | `HOSTINGER_SSH_PORT` | `65002` |
   | `HOSTINGER_SSH_USER` | `u000000000` |
   | `HOSTINGER_SSH_KEY` | the whole contents of `brandmate_deploy` (the private key) |
   | `HOSTINGER_DEPLOY_PATH` | `domains/yourdomain.com` |

3. **The `.env` on the server** must already exist in
   `<DEPLOY_PATH>/brandmate-app/.env` (see `brandmate-hostinger/DEPLOY.md`).
   The workflow never uploads or changes it.

## What a deploy does

1. Builds the Angular production app.
2. Copies `backend/` to `brandmate-app/`, skipping `.env`, `vendor`, `storage`
   and tests.
3. On the server: `composer install --no-dev`, `php artisan migrate --force`,
   and caches config, routes and views.
4. Copies the website into `public_html/`.

Nothing on the server is deleted. Seeders are not run automatically. To give
the sample creators their photos once, run over SSH:

```bash
cd ~/domains/yourdomain.com/brandmate-app && php artisan db:seed --class=SampleInfluencerSeeder --force
```
