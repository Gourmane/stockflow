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

## Tests Backend

Utilisez PHP 8.5 avec `pdo_mysql`, Composer 2 et une instance MySQL 8.0 dédiée aux tests. Les Feature Tests réinitialisent le schéma de test. Utilisez un schéma jetable `stockflow_test` et un compte `stockflow_test` sans rôle ni privilège hors de ce schéma.

1. Depuis le dépôt Backend, installez les versions du verrou avec `composer install --no-interaction`.
2. Sur une instance réservée aux tests, créez le schéma jetable et le compte dédié avec les droits ci-dessous. Si `.env.testing` est absent, créez-le à partir de `.env.testing.example` avec `Copy-Item .env.testing.example .env.testing` sous PowerShell. Renseignez l'hôte TCP, le port et le mot de passe du compte de test dans `.env.testing`. Le runner génère sa clé de session.
3. Vérifiez d'abord que le couple hôte et port correspond à l'instance dédiée, par exemple en contrôlant son processus et son répertoire de données sur la machine qui l'héberge. Confirmez ensuite l'identité du serveur et les droits avec le compte de test. Le résultat doit indiquer le port attendu, le schéma et le compte `stockflow_test`, et aucun rôle actif.

Le port `43317` était celui de l'instance temporaire arrêtée après la validation. Pour une nouvelle exécution, renseignez le port de l'instance dédiée réellement disponible. Évitez `3306` tant que vous n'avez pas établi que ce port dessert une instance réservée aux tests. Un port ou un nom de schéma ne suffit pas à établir l'isolation. Vérifiez aussi que les variables `DB_HOST`, `DB_PORT` et `DB_PASSWORD` héritées par le processus correspondent à l'instance de test, car elles peuvent prendre le pas sur `.env.testing`.

```bash
mysql --no-defaults --protocol=TCP --host=HOTE_TEST --port=PORT_TEST --user=stockflow_test --password --database=stockflow_test -e "SELECT VERSION(), @@port, DATABASE(), CURRENT_USER(), CURRENT_ROLE(), @@partial_revokes; SHOW GRANTS;"
```

Le compte nécessite `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER` sur le schéma. Avec `partial_revokes=OFF`, MySQL traite le caractère `_` comme un caractère générique dans les droits au niveau du schéma. La portée du `GRANT` doit alors être `` `stockflow\_test`.* ``. Avec `partial_revokes=ON`, utilisez `` `stockflow_test`.* ``. Le garde-fou vérifie ces droits avant les migrations Feature. [Documentation MySQL sur GRANT](https://dev.mysql.com/doc/refman/8.0/en/grant.html)

```bash
php vendor/bin/pest --testsuite=Unit
php vendor/bin/pest
```

Exécutez la suite Feature en série sur le schéma. Les tests de session utilisent la table `sessions` de MySQL et vérifient le rejeu des cookies. Les scénarios CSRF activent explicitement le middleware pendant les requêtes Laravel de test.

Pour GitHub Actions, le job Backend doit disposer de PHP 8.5, de `pdo_mysql`, de Composer 2 et d'un client MySQL pour la vérification préalable. Prévoyez une instance MySQL 8.0 et un schéma jetables par job. Créez `.env.testing` à partir du modèle dans l'espace de travail du runner, fournissez le mot de passe dédié via les secrets du job et exécutez Pest avec une configuration Laravel sans cache. Le job Frontend utilise la version de Node.js documentée dans son README et exécute `npm ci`, `npm test`, `npm run test:types` et `npm run build`. Les appels HTTP des tests Frontend sont simulés.
