# 🧪 Atelier 01 : Prise en Main & Diagnostic PostgreSQL 18

> **Atelier :** 01 — Audit Initial d'Instance & Métrologie Système (Édition Démo MIT)
> **Durée :** 1h30
> **Objectif Pédagogique (Bloom C3) :** Appliquer les requêtes de diagnostic système et valider la conformité d'une instance PostgreSQL 18
> **Environnement :** Conteneur Docker `postgres:18` (ou instance locale `formation_pg`)

---

## 1. Prérequis, Mise en Situation & Amorçage Idempotent

### Scénario Métier
Vous prenez en charge un nouveau cluster PostgreSQL 18 destiné à héberger le référentiel commandes d'une plateforme e-commerce. Avant toute ouverture de flux applicatif, vous devez réaliser un état des lieux complet : version exacte du moteur, configuration mémoire, encodage UTF-8, état des processus actifs et création d'un schéma d'audit reproductible.

### Script d'Amorçage Idempotent
Exécutez le bloc SQL ci-dessous pour préparer le schéma et la table de traçabilité de votre audit (réexécutable sans erreur) :

```sql
-- Idempotent lab setup schema and audit checkpoint table
CREATE SCHEMA IF NOT EXISTS demo_audit;

CREATE TABLE IF NOT EXISTS demo_audit.cluster_checks (
    check_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    check_name TEXT NOT NULL UNIQUE,
    observed_value TEXT NOT NULL,
    checked_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

---

## 2. Travail Pas-à-Pas

### Étape 1 — Vérifier la version et les paramètres critiques
Inspectez la version du serveur, le fuseau horaire, l'encodage serveur et la configuration mémoire principale :

```sql
-- Inspect server version and key runtime parameters
SELECT version() AS server_version;

SELECT name, setting, unit, context
FROM pg_settings
WHERE name IN (
    'server_encoding',
    'shared_buffers',
    'work_mem',
    'wal_level',
    'max_connections'
)
ORDER BY name;
```

### Étape 2 — Auditer les processus et bases actives
Relevez les sessions connectées dans `pg_stat_activity` ainsi que la taille des bases existantes :

```sql
-- Inspect active sessions and database sizes
SELECT pid, usename, datname, state, backend_type
FROM pg_stat_activity
ORDER BY backend_type, pid;

SELECT datname,
       pg_encoding_to_char(encoding) AS encoding,
       pg_size_pretty(pg_database_size(datname)) AS db_size
FROM pg_database
ORDER BY datname;
```

### Étape 3 — Consigner le résultat d'audit dans la table témoin
Enregistrez la version du serveur et le paramètre `shared_buffers` dans `demo_audit.cluster_checks` de manière idempotente :

```sql
-- Upsert cluster diagnostic observations into demo_audit.cluster_checks
INSERT INTO demo_audit.cluster_checks (check_name, observed_value)
VALUES
    ('server_version', current_setting('server_version')),
    ('shared_buffers', current_setting('shared_buffers')),
    ('server_encoding', current_setting('server_encoding'))
ON CONFLICT (check_name) DO UPDATE
SET observed_value = EXCLUDED.observed_value,
    checked_at = now();
```

### Résultats Attendus (Artefact Témoin)

```text
   check_name    | observed_value
-----------------+----------------
 server_encoding | UTF8
 server_version  | 18.x
 shared_buffers  | 128MB
(3 rows)
```

### 🏆 Défi Expert

Écrivez une requête unique qui calcule la taille totale occupée par l'ensemble des bases du cluster en mégaoctets (arrondie à 2 décimales) et affiche également le nombre de processus d'arrière-plan (`backend_type <> 'client backend'`) actuellement en cours d'exécution.

* **Commandes Solutions :**

```sql
-- Compute total cluster database size (MB) and background worker count
SELECT
    round(sum(pg_database_size(datname)) / (1024.0 * 1024.0), 2) AS total_mb,
    (SELECT count(*) FROM pg_stat_activity WHERE backend_type <> 'client backend') AS bg_workers
FROM pg_database;
```

---

## 3. Commande de Validation

Exécutez cet oracle SQL automatisé pour attester formellement la complétion de l'atelier :

```sql
-- Automated Qualiopi validation oracle for Lab 01
DO $$
DECLARE
    v_count INTEGER;
    v_encoding TEXT;
BEGIN
    SELECT count(*) INTO v_count
    FROM demo_audit.cluster_checks
    WHERE check_name IN ('server_version', 'shared_buffers', 'server_encoding');

    SELECT observed_value INTO v_encoding
    FROM demo_audit.cluster_checks
    WHERE check_name = 'server_encoding';

    IF v_count < 3 THEN
        RAISE EXCEPTION 'Audit incomplet : % enregistrement(s) trouvé(s) sur 3 attendus.', v_count;
    END IF;

    IF v_encoding <> 'UTF8' THEN
        RAISE EXCEPTION 'Encodage inattendu : % (UTF8 requis).', v_encoding;
    END IF;

    RAISE NOTICE 'VALIDATION_OK : Atelier 01 validé avec % contrôles enregistrés (encodage %).', v_count, v_encoding;
END $$;
```

---

## Grille d'Évaluation de Conformité Opérationnelle (DoD)

| Critère Qualiopi | Indicateur Vérifiable | Statut Cible |
| :--- | :--- | :--- |
| Amorçage idempotent | Schéma `demo_audit` et table `cluster_checks` créés sans erreur | Obligatoire |
| Diagnostic système | Lecture de `pg_settings`, `pg_stat_activity` et `pg_database` | Obligatoire |
| Persistance des preuves | 3 contrôles enregistrés via `ON CONFLICT DO UPDATE` | Obligatoire |
| Oracle automatisé | Message `VALIDATION_OK` retourné par le bloc `DO $$` | Obligatoire |

---

## 4. Corrigé Détaillé & Débriefing REX Expert

* **Pourquoi utiliser `current_setting(...)` et `pg_settings` ?** `current_setting()` fournit instantanément la valeur active dans la session courante, tandis que `pg_settings` expose le champ `context` (`postmaster`, `sighup`, `user`) indispensable pour anticiper si un changement impose un redémarrage.
* **Piège fréquent en production :** oublier `ON CONFLICT DO UPDATE` dans les scripts de collecte provoque des échecs lors des réexécutions planifiées (cron ou sonde Prometheus).
* **REX Terrain :** documentez systématiquement `server_encoding`, `shared_buffers` et `wal_level` dès la réception d'une machine avant tout test de charge `pgbench`.

---

## 🌟 Pour Aller Plus Loin

* Comparez `pg_stat_io` avant et après une requête analytique sur une table volumineuse.
* Testez l'outil CLI compagnon `pgscripts/pg_health_demo.py` pour automatiser une sonde TCP vers votre port PostgreSQL `5432`.
