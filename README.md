# KDO

Application web de gestion de listes de souhaits et de projets de cadeaux à offrir (en groupe ou individuellement), avec suivi des participations, cagnottes, remboursements et personnes gérées.

## Stack technique

- **Backend** : PHP 8.5, Symfony 8, Doctrine ORM 3, PostgreSQL 16
- **Frontend** : Twig, Bulma CSS, HTMX, Symfony AssetMapper & UX Icons
- **Serveur** : FrankenPHP (Caddy) via Docker
- **Qualité** : PHPUnit, Mago (lint & analyse statique)

## Développement local

### Prérequis

- Docker & Docker Compose

### Démarrage

```bash
docker compose up -d
```

L'application est accessible sur `http://localhost` et MailHog sur `http://localhost:8025`.

Les migrations Doctrine sont exécutées automatiquement au démarrage du conteneur `php`. Pour charger des données de démonstration :

```bash
docker compose exec php php bin/console doctrine:fixtures:load -n
```

### Tests et qualité du code

```bash
# Suite de tests PHPUnit
docker compose exec php php bin/phpunit

# Lint et analyse statique
vendor/bin/mago lint
vendor/bin/mago analyze
```

## Déploiement en production

Le fichier `compose.prod.yaml` s'appuie sur le stage `frankenphp_prod` du `Dockerfile` (sans dépendances de dev, avec compilation des assets via AssetMapper).

Variables d'environnement à définir (au minimum) :

- `APP_SECRET` *(obligatoire)* : clé secrète Symfony
- `POSTGRES_PASSWORD` *(obligatoire)* : mot de passe PostgreSQL
- `DEFAULT_URI` : URL publique de l'application (ex. `https://kdo.exemple.fr`)
- `SERVER_NAME` : nom de domaine servi
- `MAILER_DSN` : DSN SMTP pour les emails (réinitialisation de mot de passe, etc.)
- `MAILER_SENDER_EMAIL` : adresse d'expédition des emails

Lancement :

```bash
docker compose -f compose.yaml -f compose.prod.yaml up -d --build
```

> **Note** : Le `Caddyfile` embarqué écoute en HTTP sur le port 80 ; en production, placez l'application derrière un reverse proxy gérant HTTPS/TLS (les en-têtes `X-Forwarded-*` sont pris en charge via `TRUSTED_PROXIES`).

## Licence

Ce projet est sous licence [MIT](LICENSE).
