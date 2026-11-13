# Runs ON THE SERVER over SSH: ssh hostinger bash -s -- "<deploy path>" < locate-install.sh
# Prints WEB_DIR=... and APP_DIR=... for the live BrandMate install, or a line starting with ERR.
#   WEB_DIR = the folder the site is served from (its index.php loads brandmate-app)
#   APP_DIR = the brandmate-app that index.php loads, checked in the same order as index.php
set -e
cd ~
path="$1"
web=""
for c in "$path" "$path/public_html"; do
  if [ -n "$c" ] && [ -f "$c/index.php" ] && grep -q brandmate-app "$c/index.php"; then web="$c"; break; fi
done
if [ -z "$web" ]; then
  # Look for the BrandMate web root anywhere under domains/ (main domain or subdomain folder).
  web=$(grep -l brandmate-app domains/*/public_html/index.php domains/*/public_html/*/index.php 2>/dev/null \
    | xargs -r -n1 dirname | head -20 | tr '\n' ' ')
  set -- $web
  if [ "$#" -ne 1 ]; then echo "ERR candidates: ${web:-none}"; exit 0; fi
  web="$1"
fi
web=$(cd "$web" && pwd)
app=""
for c in "$web/../brandmate-app" "$web/../../brandmate-app"; do
  if [ -f "$c/bootstrap/app.php" ]; then app=$(cd "$c" && pwd); break; fi
done
if [ -z "$app" ]; then echo "ERR no brandmate-app with bootstrap/app.php next to $web"; exit 0; fi
echo "WEB_DIR=$web"
echo "APP_DIR=$app"
