# StockFlow — Démarrage local

Les commandes backend s'exécutent dans `backend/` et les commandes frontend dans `frontend/`, chacun dans son propre terminal.

## Backend Laravel et MySQL

Prévoyez PHP 8.5 avec l'extension `pdo_mysql`, Composer 2 et MySQL 8.0 à partir de la version 8.0.16. Vérifiez que `php -v` affiche PHP 8.5 dans votre terminal. Sous Windows, si vous utilisez Herd, ouvrez un nouveau terminal après avoir sélectionné cette version.

Si `vendor/` est absent, lancez `composer install`. Sur une première installation, copiez `.env.example` vers `.env` et lancez `php artisan key:generate` si la clé est vide.

Renseignez `DB_DATABASE`, `DB_USERNAME` et `DB_PASSWORD` avec le schéma de développement et son compte MySQL dédié. Les paramètres réseau et de session pour le développement local sont les suivants.

```dotenv
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
SESSION_DRIVER=database
SESSION_DOMAIN=null
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173,127.0.0.1:8000
```

```bash
php -m
php artisan migrate:status
php artisan serve --host=127.0.0.1 --port=8000
```

Vérifiez que `php -m` liste `pdo_mysql`. `migrate:status` consulte MySQL et indique quelles migrations ont été exécutées. Laravel stocke les sessions dans la table `sessions`, créée par `0001_01_01_000000_create_users_table`. Si la migration est en attente ou si la table manque, faites valider le schéma cible avant d'appliquer les migrations. Le script `composer setup` exécute des migrations et régénère la clé d'application.

## Frontend React et Vite

Utilisez Node.js 24 LTS et npm. Si `node_modules/` est absent, lancez `npm ci`. Dans `frontend/.env.local`, définissez la base des routes API, puis démarrez Vite.

```dotenv
VITE_API_URL=http://127.0.0.1:8000/api
```

```bash
npm run dev -- --host 127.0.0.1 --port 5173 --strictPort
```

Ouvrez `http://127.0.0.1:5173`. Ce port figure dans la configuration CORS de Laravel. `--strictPort` signale un port occupé afin que l'origine reste celle attendue.

## Cookies et CSRF

React appelle Laravel via Axios avec les cookies activés. Avant la connexion, Axios appelle `http://127.0.0.1:8000/sanctum/csrf-cookie`, puis envoie le jeton CSRF dans l'en-tête `X-XSRF-TOKEN` de la requête de connexion. Laravel authentifie la SPA avec le guard `web`, retrouve la session via son cookie et la stocke dans MySQL.

Utilisez `127.0.0.1` pour les deux serveurs. Le domaine de cookie `null` le limite à cet hôte. CORS autorise l'origine Vite avec les cookies et Sanctum reconnaît cette origine pour activer les sessions et la vérification CSRF sur les routes API.
