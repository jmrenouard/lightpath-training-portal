# 🚀 Module 02 : Premiers Pas, Cluster & Configuration PostgreSQL 18

> **Module :** 02 — Initialisation de Cluster, Contrôle & Configuration (Édition Démo MIT)
> **Durée :** 2h00
> **Objectif Pédagogique (Bloom C3) :** Appliquer les commandes d'administration
> **Formateur :** Jean-Marie Renouard — LightPath SARL

Ce module détaille l'initialisation d'un cluster PostgreSQL 18, l'organisation du répertoire `PGDATA`, la configuration dynamique via `ALTER SYSTEM` et la sécurisation réseau par `pg_hba.conf`.

---

## 1. Objectifs Pédagogiques Opérationnels

À l'issue de ce module, les participants sont capables de :

* **Appliquer (Bloom C3)** la commande `initdb` avec les encodages UTF-8 et options de sommes de contrôle.
* **Exploiter (Bloom C3)** `pg_ctl` et les unités Systemd pour démarrer, arrêter et recharger une instance.
* **Configurer (Bloom C3)** `postgresql.conf` et `ALTER SYSTEM` sans modifier manuellement `postgresql.auto.conf`.
* **Sécuriser (Bloom C3)** les accès réseau dans `pg_hba.conf` avec `scram-sha-256`.

---

## 2. Plan du Module

1. Définition d'un cluster (instance) PostgreSQL et création via `initdb`.
2. Anatomie du répertoire `PGDATA` et des sous-dossiers critiques.
3. Pilotage du cycle de vie avec `pg_ctl` et `systemctl`.
4. Hiérarchie des paramètres de configuration (`postgresql.conf` vs `ALTER SYSTEM`).
5. Contrôle d'accès réseau et authentification `pg_hba.conf`.
6. Productivité immédiate dans l'interpréteur `psql`.
7. Synthèse, Quiz formatif et prochaines étapes.

---

## 3. Initialisation d'un Cluster avec initdb

Un cluster PostgreSQL regroupe un ensemble de bases de données servies par une même instance sur un répertoire `PGDATA` unique :

```bash
# Initialize a PostgreSQL 18 cluster with UTF-8 encoding and data checksums
initdb -D /var/lib/postgresql/18/main \
  --encoding=UTF8 \
  --locale=fr_FR.UTF-8 \
  --data-checksums \
  --auth-local=peer \
  --auth-host=scram-sha-256
```

* Depuis PostgreSQL 18, les sommes de contrôle des pages (`--data-checksums`) sont activées par défaut lors de l'initialisation.

---

## 4. Anatomie du Répertoire PGDATA

Le répertoire `PGDATA` centralise la configuration, les données et les journaux transactionnels :

| Sous-répertoire / Fichier | Contenu & Rôle |
| :--- | :--- |
| `base/` | Fichiers physiques des bases de données (classés par OID) |
| `global/` | Tables système partagées (`pg_database`, `pg_authid`) |
| `pg_wal/` | Segments de journaux Write-Ahead Log (16 Mo par défaut) |
| `postgresql.conf` | Paramètres statiques et dynamiques de l'instance |
| `postgresql.auto.conf` | Paramètres gérés automatiquement par `ALTER SYSTEM` |
| `pg_hba.conf` | Matrice d'authentification hôte / base / rôle |

---

## 5. Pilotage du Cluster avec pg_ctl & Systemd

L'utilitaire `pg_ctl` contrôle directement le processus `postmaster` :

```bash
# Start, reload configuration, and gracefully stop the cluster
pg_ctl -D /var/lib/postgresql/18/main -l /var/log/postgresql/pg18.log start
pg_ctl -D /var/lib/postgresql/18/main reload
pg_ctl -D /var/lib/postgresql/18/main stop -m fast
```

> **Mode d'Arrêt Recommandé**
> * Préférez toujours `-m fast` pour interrompre les transactions en cours et effectuer un `checkpoint` propre.
> * Réservez `-m immediate` aux situations d'urgence : il provoque une reprise WAL au redémarrage.

---

## 6. Hiérarchie de Configuration : postgresql.conf vs ALTER SYSTEM

PostgreSQL applique les paramètres selon une priorité stricte, du plus global au plus spécifique :

1. Valeur par défaut compilée dans le moteur.
2. `postgresql.conf` lu au démarrage ou lors d'un `reload`.
3. `postgresql.auto.conf` écrit exclusivement par `ALTER SYSTEM` (prioritaire sur `postgresql.conf`).
4. Configuration par base ou par rôle (`ALTER DATABASE ... SET`, `ALTER ROLE ... SET`).
5. Surcharge de session (`SET work_mem = '64MB';`).

---

## 7. Modification Dynamique avec ALTER SYSTEM

L'instruction SQL `ALTER SYSTEM` évite les erreurs de syntaxe dans les fichiers de configuration :

```sql
-- Persist shared tuning parameters safely and reload dynamic settings
ALTER SYSTEM SET shared_buffers = '512MB';
ALTER SYSTEM SET work_mem = '32MB';
SELECT pg_reload_conf();

-- Inspect setting context to know if a restart is required
SELECT name, setting, unit, context
FROM pg_settings
WHERE name IN ('shared_buffers', 'work_mem', 'io_method');
```

---

## 8. Contrôle d'Accès Réseau (pg_hba.conf)

Le fichier `pg_hba.conf` est évalué séquentiellement de haut en bas ; la première règle correspondante est appliquée :

```text
# TYPE  DATABASE        USER            ADDRESS                 METHOD
local   all             postgres                                peer
host    all             all             127.0.0.1/32            scram-sha-256
hostssl formation_pg    app_reader      10.20.0.0/16            scram-sha-256
```

* Évitez `trust` en production et privilégiez `scram-sha-256` avec chiffrement TLS (`hostssl`).

---

## 9. Méta-commandes Indispensables dans psql

Le client interactif `psql` offre des raccourcis puissants pour l'administration quotidienne :

* `\l` : lister toutes les bases de données et leurs encodages.
* `\du` : afficher les rôles et attributs de sécurité (`SUPERUSER`, `LOGIN`).
* `\dt+` : inspecter les tables et leur taille disque réelle.
* `\conninfo` : vérifier la base courante, l'utilisateur, le port et l'état SSL.
* `\timing on` : mesurer le temps d'exécution exact de chaque requête.

---

## 10. Synthèse & Points Clés

* Un cluster s'initialise avec `initdb` et stocke l'ensemble des données dans `PGDATA`.
* `ALTER SYSTEM` combiné à `SELECT pg_reload_conf();` constitue la méthode recommandée pour modifier `postgresql.auto.conf`.
* La colonne `context` de `pg_settings` indique immédiatement si un redémarrage (`postmaster`) ou un rechargement (`sighup`) est requis.
* `pg_hba.conf` sécurise chaque flux selon le triplet base, utilisateur et réseau source.

---

## 11. Quiz d'Évaluation Formative

<details>
<summary>Question 1 : Quel fichier est modifié automatiquement lorsque vous exécutez une commande ALTER SYSTEM SET en SQL ?</summary>

* **A.** `postgresql.conf`
* **B.** `pg_hba.conf`
* **C.** `postgresql.auto.conf`
* **D.** `pg_ident.conf`

**Réponse correcte : C**

**Justification :** `ALTER SYSTEM` écrit ses directives uniquement dans `postgresql.auto.conf`, qui est lu en dernier et surcharge `postgresql.conf`.

</details>

<details>
<summary>Question 2 : Quelle valeur de la colonne context dans pg_settings indique qu'un redémarrage complet du cluster est obligatoire ?</summary>

* **A.** `user`
* **B.** `sighup`
* **C.** `superuser`
* **D.** `postmaster`

**Réponse correcte : D**

**Justification :** Les paramètres de contexte `postmaster` (comme `shared_buffers` ou `max_connections`) exigent un redémarrage complet de l'instance pour prendre effet.

</details>

<details>
<summary>Question 3 : Quelle méthode d'authentification est recommandée dans pg_hba.conf pour les connexions TCP en production ?</summary>

* **A.** `trust`
* **B.** `md5`
* **C.** `scram-sha-256`
* **D.** `password`

**Réponse correcte : C**

**Justification :** `scram-sha-256` assure un échange par challenge-réponse robuste et salé, sans jamais transmettre le mot de passe en clair sur le réseau.

</details>

---

## 12. Prochaines Étapes & Mise en Pratique

Passez à l'atelier associé pour mettre en œuvre ces commandes sur votre instance :

* Vérifiez l'arborescence système, les paramètres `pg_settings` et les connexions actives.
* Exécutez l'oracle de validation SQL pour enregistrer votre progression Qualiopi.
