# 🧪 Fiche Mémo Atelier 01 : Diagnostic & Contrôle Rapide

> **Référence :** Atelier 01 (Démo Open-Source MIT)
> **Formateur :** Jean-Marie Renouard — LightPath SARL

## Requêtes Essentielles

```sql
-- List databases with encoding and disk footprint
SELECT datname, pg_encoding_to_char(encoding), pg_size_pretty(pg_database_size(datname))
FROM pg_database
ORDER BY pg_database_size(datname) DESC;

-- Check parameters requiring a full restart (context = 'postmaster')
SELECT name, setting, unit
FROM pg_settings
WHERE context = 'postmaster'
  AND name IN ('shared_buffers', 'max_connections', 'wal_level');
```

## Raccourcis `psql` Utiles

* `\l` : liste des bases de données.
* `\dn` : liste des schémas (`demo_audit`, `public`).
* `\dt demo_audit.*` : tables du schéma d'audit.
* `\conninfo` : résumé de la session courante.
